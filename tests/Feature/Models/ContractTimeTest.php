<?php

namespace Tests\Feature\Models;

use App\Actions\AdjustContractMonth;
use App\Actions\ApproveEstimateVersion;
use App\Actions\ProvideContractMonth;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\ServiceContract;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class ContractTimeTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return array<string, array{string, int}> */
    public static function invalidProvisions(): array
    {
        return ['not first day' => ['2026-10-02', 100], 'invalid month' => ['2026-13-01', 100],
            'invalid year' => ['0000-10-01', 100], 'zero minutes' => ['2026-10-01', 0],
            'too large' => ['2026-10-01', 10000001]];
    }

    #[DataProvider('invalidProvisions')]
    public function test_invalid_provision_is_rejected(string $month, int $minutes): void
    {
        $operator = User::factory()->operator()->create();
        $contract = ServiceContract::factory()->signed()->create();
        $this->expectException(ValidationException::class);
        (new ProvideContractMonth)->handle($operator, $contract, $month, $minutes);
    }

    public function test_month_outside_contract_period_is_rejected(): void
    {
        $operator = User::factory()->operator()->create();
        $contract = ServiceContract::factory()->signed()->create(['ends_on' => today()->endOfMonth()]);
        $this->expectException(ValidationException::class);
        (new ProvideContractMonth)->handle($operator, $contract, today()->startOfMonth()->addMonth()->toDateString(), 100);
    }

    public function test_ledger_model_rejects_update_and_delete(): void
    {
        [, , , $month] = $this->timeFixture();
        $entry = $month->entries()->sole();
        try {
            $entry->forceFill(['minutes' => 1])->save();
            $this->fail('Mutable ledger model');
        } catch (LogicException) {
            $this->assertSame(100, $entry->fresh()->minutes);
        }
        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_month_provision_is_atomic_idempotent_and_does_not_roll_over(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $retry = (new ProvideContractMonth)->handle($operator, $request->serviceContract, $month->month->toDateString(), 100);
        $this->assertSame($month->id, $retry->id);
        $this->assertSame(1, $month->entries()->count());
        $next = (new ProvideContractMonth)->handle($operator, $request->serviceContract, $month->month->copy()->addMonth()->toDateString(), 30);
        $this->assertSame(30, $next->provided_minutes);
        $this->assertSame(30, $next->entries()->sole()->minutes);
        $this->expectException(ValidationException::class);
        (new ProvideContractMonth)->handle($operator, $request->serviceContract, $month->month->toDateString(), 200);
    }

    public function test_provision_failure_leaves_neither_month_nor_provided_entry(): void
    {
        $operator = User::factory()->operator()->create();
        $contract = ServiceContract::factory()->signed()->create();
        TimeLedgerEntry::creating(fn () => throw new RuntimeException('ledger failure'));
        try {
            (new ProvideContractMonth)->handle($operator, $contract, today()->startOfMonth()->toDateString(), 100);
            $this->fail('Expected rollback');
        } catch (RuntimeException) {
            $this->assertSame(0, ContractMonth::count());
            $this->assertSame(0, TimeLedgerEntry::count());
        } finally {
            TimeLedgerEntry::flushEventListeners();
            TimeLedgerEntry::clearBootedModels();
        }
    }

    public function test_customer_cannot_provide_time_and_other_company_cannot_read_it(): void
    {
        [$request, , $admin, $month] = $this->timeFixture();
        $other = User::factory()->customerAdmin()->create();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $month));
        $this->assertFalse(Gate::forUser($other)->allows('view', $month));
        $this->assertFalse(Gate::forUser($other)->allows('view', $month->entries()->sole()));
        $this->assertSame(0, ContractMonth::visibleTo($other)->count());
        $this->assertSame(0, TimeLedgerEntry::visibleTo($other)->count());
        $this->expectException(AuthorizationException::class);
        (new ProvideContractMonth)->handle($admin, $request->serviceContract, today()->startOfMonth()->toDateString(), 200);
    }

    /** @return array<string, array{string}> */
    public static function invalidDatabaseWrites(): array
    {
        return array_combine($keys = ['duplicate_month', 'cross_company_month', 'cross_company_entry', 'duplicate_source', 'zero_minutes', 'unknown_type', 'update_entry', 'delete_entry', 'change_budget'], array_map(fn ($key) => [$key], $keys));
    }

    #[DataProvider('invalidDatabaseWrites')]
    public function test_database_constraints_reject_invalid_writes(string $operation): void
    {
        [, , , $month] = $this->timeFixture();
        $entry = $month->entries()->sole();
        $other = ServiceContract::factory()->create();
        $monthRow = $month->getAttributes();
        unset($monthRow['id']);
        $entryRow = $entry->getAttributes();
        unset($entryRow['id']);
        $this->expectException(QueryException::class);
        match ($operation) {
            'duplicate_month' => DB::table('contract_months')->insert($monthRow),
            'cross_company_month' => DB::table('contract_months')->insert(array_replace($monthRow, ['company_id' => $other->company_id, 'month' => today()->startOfMonth()->addMonth()->toDateString()])),
            'cross_company_entry' => DB::table('time_ledger_entries')->insert(array_replace($entryRow, ['company_id' => $other->company_id, 'source_id' => 999999])),
            'duplicate_source' => DB::table('time_ledger_entries')->insert($entryRow),
            'zero_minutes' => DB::table('time_ledger_entries')->insert(array_replace($entryRow, ['minutes' => 0, 'source_id' => 999999])),
            'unknown_type' => DB::table('time_ledger_entries')->insert(array_replace($entryRow, ['type' => 'invalid', 'source_id' => 999999])),
            'update_entry' => DB::table('time_ledger_entries')->where('id', $entry->id)->update(['minutes' => 1]),
            'delete_entry' => DB::table('time_ledger_entries')->where('id', $entry->id)->delete(),
            'change_budget' => DB::table('contract_months')->where('id', $month->id)->update(['provided_minutes' => 200]),
        };
    }

    /** @return array<string, array{string}> */
    public static function blockedReservations(): array
    {
        return ['insufficient' => ['insufficient'], 'missing month' => ['missing'], 'closed month' => ['closed'], 'entry failure' => ['failure']];
    }

    #[DataProvider('blockedReservations')]
    public function test_reservation_failure_rolls_back_entire_approval(string $case): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture($case === 'insufficient' ? 59 : 100);
        if ($case === 'missing') {
            $estimate = $this->timeEstimate($operator, $request, 60, 1);
        }
        if ($case === 'closed') {
            $month->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $operator->id])->save();
        }
        if ($case === 'failure') {
            TimeLedgerEntry::creating(function (TimeLedgerEntry $entry): void {
                if ($entry->type === TimeLedgerType::Reserve) {
                    throw new RuntimeException('reservation failure');
                }
            });
        }
        $activities = $request->activities()->count();
        try {
            $this->approve($admin, $estimate);
            $this->fail('Invalid reservation accepted');
        } catch (ValidationException|RuntimeException) {
            $this->assertSame(0, EstimateApproval::count());
            $this->assertSame(0, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
            $this->assertNull($request->fresh()->approved_estimate_version_id);
            $this->assertSame(WorkRequestStatus::AwaitingApproval, $request->fresh()->status);
            $this->assertSame(0, $request->statusChanges()->count());
            $this->assertSame($activities, $request->activities()->count());
        } finally {
            TimeLedgerEntry::flushEventListeners();
            TimeLedgerEntry::clearBootedModels();
        }
    }

    public function test_exact_capacity_reserves_once(): void
    {
        [$request, , $admin, $month, $estimate] = $this->timeFixture(60);
        $key = (string) Str::uuid();
        $first = $this->approve($admin, $estimate, $key);
        $this->assertSame($first->id, $this->approve($admin, $estimate, $key)->id);
        $this->assertSame(2, $month->entries()->count());
        $entry = $month->entries()->where('type', TimeLedgerType::Reserve)->sole();
        $this->assertSame(60, $entry->minutes);
        $this->assertSame($first->id, $entry->source_id);
        $this->assertSame($request->id, $entry->work_request_id);
    }

    public function test_balance_accounts_for_usage_release_cancellation_and_adjustments(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture(100, 71);
        foreach ([['reserve', 50], ['release', 20], ['usage', 20], ['cancel_usage', 5], ['adjust_increase', 25], ['adjust_decrease', 10]] as [$type, $minutes]) {
            if (str_starts_with($type, 'adjust_')) {
                (new AdjustContractMonth)->handle($operator, $month, $month->entries()->oldest('id')->firstOrFail(),
                    TimeLedgerType::from($type), $minutes, '잔액 계산 검증', (string) Str::uuid());

                continue;
            }
            (new TimeLedgerEntry)->forceFill(['company_id' => $request->company_id, 'contract_month_id' => $month->id,
                'work_request_id' => $request->id, 'type' => $type, 'minutes' => $minutes, 'source_type' => 'test_fixture',
                'source_id' => 1, 'actor_id' => $admin->id, 'reason' => '잔액 계산 검증', 'occurred_at' => now()])->save();
        }
        // 100 - 50 + 20 - 20 + 5 + 25 - 10 = 70; a 71-minute estimate must fail.
        $this->expectException(ValidationException::class);
        $this->approve($admin, $estimate);
    }

    private function approve(User $admin, EstimateVersion $estimate, ?string $key = null): EstimateApproval
    {
        return (new ApproveEstimateVersion)->handle($admin, $estimate, $key ?? (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '203.0.113.1', 'Test');
    }
}
