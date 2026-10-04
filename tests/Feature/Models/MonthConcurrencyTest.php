<?php

namespace Tests\Feature\Models;

use App\Models\MonthAdjustment;
use App\Models\MonthClosure;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class MonthConcurrencyTest extends TestCase
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
        return ['close twice' => ['close'], 'overdraft' => ['adjust'], 'retry' => ['retry'],
            'close versus adjust' => ['close_adjust'], 'close versus draft' => ['close_draft']];
    }

    #[DataProvider('races')]
    public function test_month_operations_serialize(string $case): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $other = $case === 'retry' ? $operator : User::factory()->operator()->create();
        $this->travelTo($month->month->copy()->addMonth());
        $key = (string) Str::uuid();
        $actions = match ($case) {
            'close' => ['close', 'close'], 'adjust', 'retry' => ['adjust', 'adjust'],
            'close_adjust' => ['close', 'adjust'], 'close_draft' => ['close', 'draft'],
        };
        $table = $case === 'retry' ? 'users' : 'contract_months';
        $workers = [];
        DB::beginTransaction();
        DB::table($table)->where('id', $case === 'retry' ? $operator->id : $month->id)->lockForUpdate()->first();
        try {
            foreach ([$operator, $other] as $index => $actor) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/month-operation-worker.php')], base_path());
                $worker->setTimeout(25);
                $worker->setInput(json_encode(['database' => config('database.connections.mysql'), 'app_key' => config('app.key'),
                    'actor' => $actor->id, 'month' => $month->id, 'related' => $month->entries()->sole()->id,
                    'request' => $request->id, 'key' => $case === 'retry' ? $key : (string) Str::uuid(),
                    'action' => $actions[$index], 'now' => now()->toDateTimeString(), 'lock_table' => $table], JSON_THROW_ON_ERROR)."\n");
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = count(array_filter($workers, fn (Process $worker): bool => str_contains($worker->getOutput(), 'LOCKING')));
                if ($ready === 2) {
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
            $this->assertSame(in_array($case, ['adjust', 'close_draft'], true) ? ['ok', 'rejected'] : ['ok', 'ok'], $statuses);
            if ($case === 'retry' || $case === 'close') {
                $this->assertSame($results[0]['id'], $results[1]['id']);
            }
            if (in_array($case, ['adjust', 'retry', 'close_adjust'], true)) {
                $this->assertSame(1, MonthAdjustment::count());
                $this->assertSame(40, $month->entries()->get()->sum(fn (TimeLedgerEntry $entry) => $entry->minutes * $entry->type->availableSign()));
            }
            if ($case === 'close_draft') {
                $this->assertSame(1, MonthClosure::count() + WorkLog::count());
                $this->assertSame(MonthClosure::count() === 1 ? 'closed' : 'open', $month->fresh()->status);
            } elseif (in_array($case, ['close', 'close_adjust'], true)) {
                $this->assertSame(1, MonthClosure::count());
                $this->assertSame('closed', $month->fresh()->status);
            }
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
