<?php

namespace Tests\Feature\Models;

use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\EstimateApproval;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class ApprovalConcurrencyTest extends TestCase
{
    // Workers must see committed fixtures, not RefreshDatabase's outer transaction.
    use BuildsContractTime, DatabaseMigrations;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function races(): array
    {
        return ['shared capacity' => ['capacity'], 'exact shared capacity' => ['capacity_exact'],
            'same key retry' => ['retry'], 'different key duplicate' => ['duplicate']];
    }

    #[DataProvider('races')]
    public function test_concurrent_approvals_are_serialized(string $case): void
    {
        $sharedMonth = in_array($case, ['capacity', 'capacity_exact'], true);
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture($case === 'capacity_exact' ? 120 : 100);
        $otherEstimate = $estimate;
        $otherAdmin = $admin;
        if ($sharedMonth) {
            $otherRequest = WorkRequest::factory()->create(['company_id' => $request->company_id,
                'service_contract_id' => $request->service_contract_id, 'status' => WorkRequestStatus::AwaitingApproval]);
            $otherAdmin = User::factory()->customerAdmin()->for($request->company)->create();
            $otherEstimate = $this->timeEstimate($operator, $otherRequest);
        }
        $key = (string) Str::uuid();
        $table = $sharedMonth ? 'contract_months' : 'work_requests';
        $id = $sharedMonth ? $month->id : $request->id;
        $workers = [];
        DB::beginTransaction();
        DB::table($table)->where('id', $id)->lockForUpdate()->first();
        try {
            foreach ([[$admin, $estimate, $key], [$otherAdmin, $otherEstimate, $case === 'retry' ? $key : (string) Str::uuid()]] as [$actor, $version, $token]) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/approve-estimate-worker.php')], base_path());
                $worker->setInput(json_encode(['database' => config('database.connections.mysql'), 'app_key' => config('app.key'),
                    'actor' => $actor->id, 'estimate' => $version->id, 'key' => $token, 'lock_table' => $table], JSON_THROW_ON_ERROR)."\n");
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = 0;
                foreach ($workers as $worker) {
                    $ready += str_contains($worker->getOutput(), 'LOCKING') ? 1 : 0;
                }
                if ($ready === count($workers)) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            foreach ($workers as $worker) {
                $this->assertStringContainsString('LOCKING', $worker->getOutput(), $worker->getErrorOutput());
                $this->assertStringNotContainsString('RESULT:', $worker->getOutput());
            }
            // Both independent connections reached the guarded locking read while
            // this connection held its row. No timing sleeps are used as a barrier.
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
            $this->assertSame(in_array($case, ['retry', 'capacity_exact'], true) ? ['approved', 'approved'] : ['approved', 'rejected'], $statuses);
            if ($case === 'retry') {
                $this->assertSame($results[0]['id'], $results[1]['id']);
            }
            if ($case === 'capacity') {
                $rejection = array_values(array_filter($results, fn ($result) => $result['status'] === 'rejected'))[0];
                $this->assertSame(['minutes'], $rejection['fields']);
                $this->assertSame(1, WorkRequest::where('status', WorkRequestStatus::AwaitingApproval)->count());
                $this->assertSame(1, WorkRequest::whereNull('approved_estimate_version_id')->count());
            }
            $approvedCount = $case === 'capacity_exact' ? 2 : 1;
            $this->assertSame($approvedCount, EstimateApproval::count());
            $this->assertSame($approvedCount, WorkRequestStatusChange::count());
            $this->assertSame($approvedCount, WorkRequest::where('status', WorkRequestStatus::Queued)->count());
            $this->assertSame($approvedCount, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
            $this->assertSame(60 * $approvedCount, (int) TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->sum('minutes'));
            $this->assertSame($case === 'capacity_exact' ? 0 : 40, ContractMonth::findOrFail($month->id)->entries()->get()->sum(fn (TimeLedgerEntry $entry) => $entry->minutes * $entry->type->availableSign()));
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
