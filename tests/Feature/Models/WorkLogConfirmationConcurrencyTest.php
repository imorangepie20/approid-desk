<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\SaveWorkLogDraft;
use App\Enums\WorkLogStatus;
use App\Models\TimeLedgerEntry;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class WorkLogConfirmationConcurrencyTest extends TestCase
{
    use BuildsContractTime, DatabaseMigrations;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function races(): array
    {
        return [
            'same record retry' => ['retry'],
            'two records competing for reservation' => ['compete'],
            'two records exactly consume reservation' => ['exact'],
        ];
    }

    #[DataProvider('races')]
    public function test_confirmations_serialize(string $case): void
    {
        $retry = $case === 'retry';
        $minutes = $case === 'exact' ? 30 : 45;
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Concurrency test');
        $input = ['worked_on' => today()->toDateString(), 'minutes' => $minutes, 'description' => '동시 확정', 'is_billable' => true];
        $first = (new SaveWorkLogDraft)->handle($operator, $request, $input);
        $second = $retry ? $first : (new SaveWorkLogDraft)->handle($operator, $request, $input);
        $workers = [];
        DB::beginTransaction();
        DB::table('work_requests')->where('id', $request->id)->lockForUpdate()->first();
        try {
            foreach ([$first, $second] as $log) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/month-operation-worker.php')], base_path());
                $worker->setTimeout(25);
                $worker->setInput(json_encode(['database' => config('database.connections.mysql'), 'app_key' => config('app.key'),
                    'actor' => $operator->id, 'month' => $month->id, 'log' => $log->id,
                    'action' => 'confirm', 'now' => now()->toDateTimeString(), 'lock_table' => 'work_requests'], JSON_THROW_ON_ERROR)."\n");
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 8;
            do {
                if (count(array_filter($workers, fn (Process $worker): bool => str_contains($worker->getOutput(), 'LOCKING'))) === 2) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            foreach ($workers as $worker) {
                $this->assertStringContainsString('LOCKING', $worker->getOutput(), $worker->getErrorOutput());
                $this->assertStringNotContainsString('RESULT:', $worker->getOutput());
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                preg_match('/RESULT:(.+)/', $worker->getOutput(), $match);
                $results[] = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame($case === 'compete' ? ['ok', 'rejected'] : ['ok', 'ok'], $statuses);
            if ($retry) {
                $this->assertSame($results[0]['id'], $results[1]['id']);
            }
            if ($case === 'compete') {
                $rejection = collect($results)->firstWhere('status', 'rejected');
                $this->assertSame(['minutes'], $rejection['fields']);
            }
            $confirmedCount = $case === 'exact' ? 2 : 1;
            $confirmedMinutes = $case === 'exact' ? 60 : 45;
            $this->assertSame($confirmedCount, WorkLog::where('status', WorkLogStatus::Confirmed)->count());
            $this->assertSame(2 + (2 * $confirmedCount), TimeLedgerEntry::count());
            $this->assertSame($confirmedMinutes, (int) $month->entries()->where('type', 'usage')->sum('minutes'));
            $this->assertSame($confirmedMinutes, (int) $month->entries()->where('type', 'release')->sum('minutes'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }

    public function test_same_non_billable_record_confirmation_retries_serialize_without_ledger_entries(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Non-billable concurrency test');
        $log = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'minutes' => 45,
            'description' => '동시 비차감 확정', 'is_billable' => false,
            'non_billable_reason' => '개발자 귀책 수정',
        ]);
        $workers = [];
        DB::beginTransaction();
        DB::table('work_requests')->where('id', $request->id)->lockForUpdate()->first();
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/month-operation-worker.php')], base_path());
                $worker->setTimeout(25);
                $worker->setInput(json_encode(['database' => config('database.connections.mysql'), 'app_key' => config('app.key'),
                    'actor' => $operator->id, 'month' => $month->id, 'log' => $log->id,
                    'action' => 'confirm_non_billable', 'now' => now()->toDateTimeString(),
                    'lock_table' => 'work_requests'], JSON_THROW_ON_ERROR)."\n");
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 8;
            do {
                if (count(array_filter($workers, fn (Process $worker): bool => str_contains($worker->getOutput(), 'LOCKING'))) === 2) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            foreach ($workers as $worker) {
                $this->assertStringContainsString('LOCKING', $worker->getOutput(), $worker->getErrorOutput());
                $this->assertStringNotContainsString('RESULT:', $worker->getOutput());
            }
            DB::commit();
            $ids = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                preg_match('/RESULT:(.+)/', $worker->getOutput(), $match);
                $result = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('ok', $result['status']);
                $ids[] = $result['id'];
            }
            $this->assertSame($ids[0], $ids[1]);
            $confirmed = WorkLog::findOrFail($log->id);
            $this->assertSame(WorkLogStatus::Confirmed, $confirmed->status);
            $this->assertSame(2, $confirmed->revision);
            $this->assertSame($operator->id, $confirmed->confirmed_by);
            $this->assertSame(2, TimeLedgerEntry::count());
            $this->assertSame(0, $month->entries()->whereIn('type', ['usage', 'release'])->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }
}
