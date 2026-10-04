<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\ProvideContractMonth;
use App\Actions\TransitionWorkRequest;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\EstimateApproval;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class EstimateReservationReplacementConcurrencyTest extends TestCase
{
    use BuildsContractTime, DatabaseMigrations;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_concurrent_replacements_share_target_capacity_without_losing_old_reservations(): void
    {
        [$firstRequest, $operator, $firstAdmin, $oldMonth, $firstEstimate] = $this->timeFixture(200);
        $secondRequest = WorkRequest::factory()->create([
            'company_id' => $firstRequest->company_id,
            'service_contract_id' => $firstRequest->service_contract_id,
            'status' => WorkRequestStatus::AwaitingApproval,
        ]);
        $secondAdmin = User::factory()->customerAdmin()->for($firstRequest->company)->create();
        $secondEstimate = $this->timeEstimate($operator, $secondRequest);
        foreach ([[$firstAdmin, $firstEstimate], [$secondAdmin, $secondEstimate]] as [$admin, $estimate]) {
            (new ApproveEstimateVersion)->handle(
                $admin,
                $estimate,
                (string) Str::uuid(),
                ApproveEstimateVersion::APPROVAL_TEXT,
                '127.0.0.1',
                'Replacement concurrency setup',
            );
        }
        $targetMonth = (new ProvideContractMonth)->handle(
            $operator,
            $firstRequest->serviceContract,
            today()->startOfMonth()->addMonth()->toDateString(),
            100,
        );
        $firstReplacement = $this->timeEstimate($operator, $firstRequest, 60, 1);
        $secondReplacement = $this->timeEstimate($operator, $secondRequest, 60, 1);
        (new TransitionWorkRequest)->handle($operator, $firstRequest, WorkRequestStatus::AwaitingApproval);
        (new TransitionWorkRequest)->handle($operator, $secondRequest, WorkRequestStatus::AwaitingApproval);

        $workers = [];
        DB::beginTransaction();
        DB::table('contract_months')->where('id', $targetMonth->id)->lockForUpdate()->first();
        try {
            foreach ([[$firstAdmin, $firstReplacement], [$secondAdmin, $secondReplacement]] as [$admin, $estimate]) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/approve-estimate-worker.php')], base_path());
                $worker->setInput(json_encode([
                    'database' => config('database.connections.mysql'),
                    'app_key' => config('app.key'),
                    'actor' => $admin->id,
                    'estimate' => $estimate->id,
                    'key' => (string) Str::uuid(),
                    'lock_table' => 'contract_months',
                ], JSON_THROW_ON_ERROR)."\n");
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }

            $deadline = microtime(true) + 8;
            do {
                $ready = count(array_filter($workers, fn (Process $worker): bool => str_contains($worker->getOutput(), 'LOCKING')));
                if ($ready === count($workers)) {
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
            $this->assertSame(['approved', 'rejected'], $statuses);
            $rejection = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'rejected'))[0];
            $this->assertSame(['minutes'], $rejection['fields']);

            $this->assertSame(3, EstimateApproval::count());
            $this->assertSame(3, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
            $this->assertSame(1, TimeLedgerEntry::where('source_type', 'estimate_replacement')->count());
            $this->assertSame(60, (int) $targetMonth->entries()->where('type', TimeLedgerType::Reserve)->sum('minutes'));
            $this->assertSame(60, $this->remainingReservation($oldMonth));
            $this->assertSame(1, WorkRequest::where('status', WorkRequestStatus::Queued)->count());
            $this->assertSame(1, WorkRequest::where('status', WorkRequestStatus::AwaitingApproval)->count());
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

    private function remainingReservation(ContractMonth $month): int
    {
        return $month->entries()->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])
            ->get()->sum(fn (TimeLedgerEntry $entry): int => $entry->type === TimeLedgerType::Reserve
                ? $entry->minutes : -$entry->minutes);
    }
}
