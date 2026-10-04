<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\SaveWorkLogDraft;
use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Enums\WorkRequestStatus;
use App\Models\TimeLedgerEntry;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class TerminalReservationConcurrencyTest extends TestCase
{
    use BuildsContractTime, DatabaseMigrations;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_confirmation_and_cancellation_serialize_without_leaking_reservation(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Terminal race test');
        $log = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'minutes' => 45,
            'description' => '확정 대 취소', 'is_billable' => true,
        ]);
        $workers = [];
        DB::beginTransaction();
        DB::table('work_requests')->where('id', $request->id)->lockForUpdate()->first();
        try {
            foreach (['confirm', 'cancel'] as $action) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/month-operation-worker.php')], base_path());
                $worker->setTimeout(25);
                $worker->setInput(json_encode(['database' => config('database.connections.mysql'), 'app_key' => config('app.key'),
                    'actor' => $operator->id, 'month' => $month->id, 'request' => $request->id, 'log' => $log->id,
                    'action' => $action, 'now' => now()->toDateTimeString(), 'lock_table' => 'work_requests'], JSON_THROW_ON_ERROR)."\n");
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
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                preg_match('/RESULT:(.+)/', $worker->getOutput(), $match);
                $results[] = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(WorkRequestStatus::Cancelled, $request->fresh()->status);
            $this->assertContains($results[0]['status'], ['ok', 'rejected']);
            $this->assertSame('ok', $results[1]['status']);
            $usage = (int) $month->entries()->where('type', TimeLedgerType::Usage)->sum('minutes');
            $this->assertContains($usage, [0, 45]);
            $this->assertSame(60, (int) $month->entries()->where('type', TimeLedgerType::Release)->sum('minutes'));
            $this->assertSame($usage === 45 ? 1 : 0, WorkLog::where('status', WorkLogStatus::Confirmed)->count());
            $this->assertSame(100 - $usage, $month->entries()->get()->sum(
                fn (TimeLedgerEntry $entry): int => $entry->minutes * $entry->type->availableSign()
            ));
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
