<?php

namespace Tests\Feature\Models;

use App\Actions\AdjustContractMonth;
use App\Actions\CloseContractMonth;
use App\Actions\ProvideContractMonth;
use App\Actions\SaveWorkLogDraft;
use App\Enums\TimeLedgerType as Type;
use App\Models\ContractMonth;
use App\Models\MonthAdjustment;
use App\Models\MonthClosure;
use App\Models\ServiceContract;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class MonthClosingTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    private function entry(ContractMonth $month, User $actor, Type $type, int $minutes, ?int $request = null): TimeLedgerEntry
    {
        $entry = (new TimeLedgerEntry)->forceFill(['company_id' => $month->company_id, 'contract_month_id' => $month->id,
            'work_request_id' => $request, 'type' => $type, 'minutes' => $minutes, 'source_type' => 'closing_fixture',
            'source_id' => random_int(1000, 100000000), 'actor_id' => $actor->id, 'reason' => '합계 검증', 'occurred_at' => now()]);
        $entry->save();

        return $entry;
    }

    public function test_month_closing_saves_exact_immutable_totals_and_retries_without_duplicate(): void
    {
        [$request, $operator, , $month] = $this->timeFixture(120);
        $this->entry($month, $operator, Type::Reserve, 60, $request->id);
        $this->entry($month, $operator, Type::Release, 60, $request->id);
        $this->entry($month, $operator, Type::Usage, 50, $request->id);
        $this->entry($month, $operator, Type::CancelUsage, 10, $request->id);
        $related = $month->entries()->oldest('id')->firstOrFail();
        (new AdjustContractMonth)->handle($operator, $month, $related, Type::AdjustIncrease, 20, '추가 제공', (string) Str::uuid());
        (new AdjustContractMonth)->handle($operator, $month, $related, Type::AdjustDecrease, 5, '제공 정정', (string) Str::uuid());
        $this->travelTo($month->month->copy()->addMonth());
        $closure = (new CloseContractMonth)->handle($operator, $month);
        $this->assertSame(['provided' => 120, 'reserve' => 60, 'release' => 60, 'usage' => 50, 'cancel_usage' => 10,
            'adjust_increase' => 20, 'adjust_decrease' => 5, 'remaining_reserved' => 0, 'net_usage' => 40, 'available' => 95], $closure->totals);
        $this->assertSame(7, $closure->entry_count);
        $this->assertSame('closed', $month->fresh()->status);
        $this->assertSame($operator->id, $month->fresh()->closed_by);
        $this->assertSame($closure->id, (new CloseContractMonth)->handle($operator, $month)->id);
        $this->assertSame(1, MonthClosure::count());
        $this->expectException(QueryException::class);
        DB::table('month_closures')->where('id', $closure->id)->update(['entry_count' => 1]);
    }

    public function test_active_month_and_outstanding_reservations_block_closing(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        try {
            (new CloseContractMonth)->handle($operator, $month);
            $this->fail('Current month closed');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('month', $error->errors());
        }
        $this->entry($month, $operator, Type::Reserve, 20, $request->id);
        $this->travelTo($month->month->copy()->addMonth());
        try {
            (new CloseContractMonth)->handle($operator, $month);
            $this->fail('Reserved month closed');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('ledger', $error->errors());
        }
        $this->assertSame('open', $month->fresh()->status);
        $this->assertSame(0, MonthClosure::count());
    }

    public function test_pending_work_logs_block_closing_and_closed_month_rejects_backdated_drafts(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $input = ['worked_on' => today()->toDateString(), 'minutes' => 30, 'description' => '미확정 작업', 'is_billable' => true];
        $log = (new SaveWorkLogDraft)->handle($operator, $request, $input);
        $this->travelTo($month->month->copy()->addMonth());
        try {
            (new CloseContractMonth)->handle($operator, $month);
            $this->fail('Draft month closed');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('work_logs', $error->errors());
        }
        // Discard only this test draft to exercise the independent closed-period guard.
        $log->delete();
        (new CloseContractMonth)->handle($operator, $month);
        $this->expectException(ValidationException::class);
        (new SaveWorkLogDraft)->handle($operator, $request, $input);
    }

    public function test_closed_adjustment_keeps_snapshot_and_original_entries_unchanged(): void
    {
        [, $operator, $admin, $month] = $this->timeFixture();
        $related = $month->entries()->sole();
        $before = $related->toArray();
        $this->travelTo($month->month->copy()->addMonth());
        $closure = (new CloseContractMonth)->handle($operator, $month);
        $key = (string) Str::uuid();
        $adjustment = (new AdjustContractMonth)->handle($operator, $month, $related, Type::AdjustDecrease, 30, '과다 제공 정정', $key);
        $this->assertSame($operator->id, $adjustment->approved_by);
        $this->assertSame($related->id, $adjustment->related_entry_id);
        $this->assertSame($adjustment->id, (new AdjustContractMonth)->handle($operator, $month, $related, Type::AdjustDecrease, 30, '과다 제공 정정', strtoupper($key))->id);
        $this->assertSame(1, MonthAdjustment::count());
        $this->assertSame(2, $month->entries()->count());
        $this->assertSame($before, $related->fresh()->toArray());
        $this->assertEquals($closure->totals, $closure->fresh()->totals);
        $this->assertSame(100, $closure->totals['available']);
        $this->assertSame(70, $month->entries()->get()->sum(fn (TimeLedgerEntry $entry) => $entry->minutes * $entry->type->availableSign()));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $adjustment));
        $this->assertFalse(Gate::forUser(User::factory()->customerAdmin()->create())->allows('view', $closure));
    }

    public function test_adjustment_payload_reuse_wrong_month_and_overdraft_are_rejected(): void
    {
        [, $operator, , $month] = $this->timeFixture();
        $related = $month->entries()->sole();
        $key = (string) Str::uuid();
        (new AdjustContractMonth)->handle($operator, $month, $related, Type::AdjustDecrease, 60, '정정', $key);
        foreach ([[50, $key, 'idempotency_key'], [50, (string) Str::uuid(), 'minutes']] as [$minutes, $token, $field]) {
            try {
                (new AdjustContractMonth)->handle($operator, $month, $related, Type::AdjustDecrease, $minutes, '정정', $token);
                $this->fail('Invalid adjustment accepted');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey($field, $error->errors());
            }
        }
        $this->assertSame(1, MonthAdjustment::count());
        $other = (new ProvideContractMonth)->handle($operator,
            ServiceContract::findOrFail($month->service_contract_id), $month->month->copy()->addMonth()->toDateString(), 100);
        $this->expectException(ValidationException::class);
        (new AdjustContractMonth)->handle($operator, $other, $related, Type::AdjustIncrease, 10, '타 월', (string) Str::uuid());
    }

    /** @return array<string, array{Type, int, string, string}> */
    public static function invalidAdjustments(): array
    {
        $key = '9d1ecb08-9017-4f46-b252-924932736b0d';

        return ['type' => [Type::Usage, 1, '사유', $key], 'zero' => [Type::AdjustIncrease, 0, '사유', $key],
            'negative' => [Type::AdjustIncrease, -1, '사유', $key], 'large' => [Type::AdjustIncrease, 10000001, '사유', $key],
            'reason' => [Type::AdjustIncrease, 1, ' ', $key], 'key' => [Type::AdjustIncrease, 1, '사유', 'invalid']];
    }

    #[DataProvider('invalidAdjustments')]
    public function test_invalid_adjustments(Type $type, int $minutes, string $reason, string $key): void
    {
        [, $operator, , $month] = $this->timeFixture();
        $this->expectException(ValidationException::class);
        (new AdjustContractMonth)->handle($operator, $month, $month->entries()->sole(), $type, $minutes, $reason, $key);
    }

    public function test_customers_and_stale_deactivated_operator_cannot_manage_months(): void
    {
        [, $operator, $admin, $month] = $this->timeFixture();
        $this->travelTo($month->month->copy()->addMonth());
        User::query()->whereKey($operator->id)->update(['is_active' => false]);
        foreach ([$operator, $admin, User::factory()->customerUser()->for($admin->company)->create()] as $actor) {
            foreach (['close', 'adjust'] as $action) {
                try {
                    $action === 'close' ? (new CloseContractMonth)->handle($actor, $month)
                        : (new AdjustContractMonth)->handle($actor, $month, $month->entries()->sole(), Type::AdjustIncrease, 1, '권한 검사', (string) Str::uuid());
                    $this->fail('Unauthorized mutation');
                } catch (AuthorizationException) {
                    $this->assertSame(0, MonthAdjustment::count());
                    $this->assertSame('open', $month->fresh()->status);
                }
            }
        }
    }

    public function test_closed_month_rejects_unapproved_ledger_insert_and_audit_deletion(): void
    {
        [, $operator, , $month] = $this->timeFixture();
        $this->travelTo($month->month->copy()->addMonth());
        $closure = (new CloseContractMonth)->handle($operator, $month);
        foreach ([Type::Provided, Type::AdjustIncrease] as $type) {
            try {
                $this->entry($month, $operator, $type, 10);
                $this->fail('Unapproved entry inserted');
            } catch (QueryException) {
                $this->assertSame(1, $month->entries()->count());
            }
        }
        $this->expectException(QueryException::class);
        DB::table('month_closures')->where('id', $closure->id)->delete();
    }

    public function test_audit_failure_rolls_back_close(): void
    {
        [, $operator, , $month] = $this->timeFixture();
        $this->travelTo($month->month->copy()->addMonth());
        MonthClosure::created(fn () => throw new RuntimeException('audit failure'));
        try {
            (new CloseContractMonth)->handle($operator, $month);
            $this->fail('Expected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failure', $error->getMessage());
        }
        $this->assertSame('open', $month->fresh()->status);
        $this->assertSame(0, MonthClosure::count());
    }

    public function test_ledger_failure_rolls_back_adjustment(): void
    {
        [, $operator, , $month] = $this->timeFixture();
        TimeLedgerEntry::creating(fn () => throw new RuntimeException('ledger failure'));
        try {
            (new AdjustContractMonth)->handle($operator, $month, $month->entries()->sole(), Type::AdjustIncrease, 1, '정정', (string) Str::uuid());
            $this->fail('Expected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('ledger failure', $error->getMessage());
        }
        $this->assertSame(0, MonthAdjustment::count());
        $this->assertSame(1, $month->entries()->count());
    }

    public function test_database_guards_closed_period_work_logs_even_when_bypassing_services(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $log = (new SaveWorkLogDraft)->handle($operator, $request,
            ['worked_on' => today()->toDateString(), 'minutes' => 30, 'description' => '보호 검사', 'is_billable' => true]);
        // Legacy/inconsistent closed fixture: the public close action rejects this draft.
        $month->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $operator->id])->save();
        $row = (array) DB::table('work_logs')->where('id', $log->id)->first();
        unset($row['id']);
        foreach (['insert', 'update', 'delete'] as $operation) {
            try {
                $query = DB::table('work_logs')->where('id', $log->id);
                match ($operation) {
                    'insert' => DB::table('work_logs')->insert($row),
                    'update' => $query->update(['worked_on' => today()->addMonth()->toDateString()]),
                    'delete' => $query->delete(),
                };
                $this->fail('Closed work log changed');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Closed period work logs cannot be changed', $error->getMessage());
            }
        }
        $this->assertSame(1, WorkLog::count());
    }

    public function test_adjustment_records_are_immutable_and_open_months_require_approval_records_too(): void
    {
        [, $operator, , $month] = $this->timeFixture();
        try {
            $this->entry($month, $operator, Type::AdjustIncrease, 10);
            $this->fail('Unapproved adjustment inserted');
        } catch (QueryException) {
            $this->assertSame(1, $month->entries()->count());
        }
        $adjustment = (new AdjustContractMonth)->handle($operator, $month, $month->entries()->sole(), Type::AdjustIncrease, 10, '제공 정정', (string) Str::uuid());
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('month_adjustments')->where('id', $adjustment->id);
                $operation === 'update' ? $query->update(['minutes' => 1]) : $query->delete();
                $this->fail('Audit record changed');
            } catch (QueryException) {
                $this->assertSame(10, $adjustment->fresh()->minutes);
            }
        }
        $this->expectException(\LogicException::class);
        $adjustment->forceFill(['reason' => '변조'])->save();
    }

    /** @return array<string, array{Type, int}> */
    public static function invalidBalances(): array
    {
        return ['release without reserve' => [Type::Release, 1], 'negative usage' => [Type::CancelUsage, 1],
            'overdrawn' => [Type::Usage, 101]];
    }

    #[DataProvider('invalidBalances')]
    public function test_invalid_balances_prevent_close(Type $type, int $minutes): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        $this->entry($month, $operator, $type, $minutes, $request->id);
        $this->travelTo($month->month->copy()->addMonth());
        $this->expectException(ValidationException::class);
        (new CloseContractMonth)->handle($operator, $month);
    }
}
