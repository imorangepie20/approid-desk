<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\CloseContractMonth;
use App\Actions\ConfirmWorkLog;
use App\Actions\SaveWorkLogDraft;
use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\EstimateVersion;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class WorkLogConfirmationTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    private function draft(User $operator, WorkRequest $request, int $minutes = 45): WorkLog
    {
        return (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '확정 검증', 'minutes' => $minutes, 'is_billable' => true,
        ]);
    }

    /** @return array{WorkRequest, User, User, ContractMonth, EstimateVersion} */
    private function approved(): array
    {
        $fixture = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($fixture[2], $fixture[4], (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Confirmation test');

        return $fixture;
    }

    public function test_confirmation_converts_reservation_once_and_preserves_available_balance(): void
    {
        [$request, $operator, , $month] = $this->approved();
        $log = $this->draft($operator, $request);
        $confirmed = (new ConfirmWorkLog)->handle($operator, $log, 1);
        $this->assertSame(WorkLogStatus::Confirmed, $confirmed->status);
        $this->assertSame(2, $confirmed->revision);
        $this->assertNotNull($confirmed->confirmed_at);
        $entries = TimeLedgerEntry::where('source_type', 'work_log')->where('source_id', $log->id)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame([TimeLedgerType::Release, TimeLedgerType::Usage], $entries->pluck('type')->all());
        foreach ($entries as $entry) {
            $this->assertSame(45, $entry->minutes);
            $this->assertSame($operator->id, $entry->actor_id);
            $this->assertSame($request->id, $entry->work_request_id);
            $this->assertSame($month->id, $entry->contract_month_id);
            $this->assertTrue($confirmed->confirmed_at->equalTo($entry->occurred_at));
        }
        $this->assertSame(40, $month->entries()->get()->sum(fn (TimeLedgerEntry $entry) => $entry->minutes * $entry->type->availableSign()));
        $this->assertSame($confirmed->id, (new ConfirmWorkLog)->handle($operator, $log, 1)->id);
        $this->assertSame(4, TimeLedgerEntry::count());
        $second = $this->draft($operator, $request, 15);
        (new ConfirmWorkLog)->handle($operator, $second, 1);
        $this->assertSame(60, (int) $month->entries()->where('type', 'usage')->sum('minutes'));
        $this->assertSame(60, (int) $month->entries()->where('type', 'release')->sum('minutes'));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidConfirmations(): array
    {
        return ['excess' => ['excess', 'minutes'], 'unapproved' => ['unapproved', 'estimate'],
            'other month' => ['period', 'worked_on'], 'closed' => ['closed', 'month'],
            'stale' => ['stale', 'revision'], 'zero revision' => ['zero', 'revision'],
            'nonbillable' => ['nonbillable', 'is_billable'], 'completed' => ['completed', 'request'],
            'cancelled' => ['cancelled', 'request'], 'other request reservation' => ['other', 'minutes']];
    }

    #[DataProvider('invalidConfirmations')]
    public function test_invalid_confirmation_has_no_partial_effect(string $case, string $field): void
    {
        [$request, $operator, $admin, $month] = $case === 'unapproved' ? $this->timeFixture() : $this->approved();
        $log = $this->draft($operator, $request, $case === 'excess' ? 61 : 45);
        if ($case === 'period') {
            $log->forceFill(['worked_on' => today()->startOfMonth()->subMonth()])->save();
        } elseif ($case === 'closed') {
            // Deliberately construct an inconsistent closed fixture to exercise the guard.
            $month->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $operator->id])->save();
        } elseif ($case === 'stale') {
            $log->forceFill(['revision' => 2])->save();
        } elseif ($case === 'nonbillable') {
            $log->forceFill(['is_billable' => false, 'non_billable_reason' => '무상 수정'])->save();
        } elseif (in_array($case, ['completed', 'cancelled'], true)) {
            DB::table('work_requests')->where('id', $request->id)->update(['status' => $case]);
        } elseif ($case === 'other') {
            // Another request shares the contract but has only 10 reserved minutes.
            $other = WorkRequest::factory()->create(['company_id' => $request->company_id,
                'service_contract_id' => $request->service_contract_id, 'status' => WorkRequestStatus::Queued]);
            $estimate = $this->timeEstimate($operator, $other, 10);
            DB::table('work_requests')->where('id', $other->id)->update(['status' => WorkRequestStatus::AwaitingApproval->value]);
            (new ApproveEstimateVersion)->handle($admin,
                $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'test');
            $log = $this->draft($operator, $other, 45);
        }
        $count = TimeLedgerEntry::count();
        try {
            (new ConfirmWorkLog)->handle($operator, $log, $case === 'zero' ? 0 : 1);
            $this->fail('Expected validation failure');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
        $this->assertSame(WorkLogStatus::Draft, $log->fresh()->status);
        $this->assertNull($log->fresh()->confirmed_at);
        $this->assertSame($count, TimeLedgerEntry::count());
    }

    public function test_permissions_are_reloaded_and_only_worker_or_superadmin_can_confirm(): void
    {
        [$request, $operator, $customer] = $this->approved();
        $log = $this->draft($operator, $request);
        foreach ([$customer, User::factory()->operator()->create()] as $actor) {
            try {
                (new ConfirmWorkLog)->handle($actor, $log, 1);
                $this->fail('Expected authorization failure');
            } catch (AuthorizationException) {
                $this->assertSame(WorkLogStatus::Draft, $log->fresh()->status);
            }
        }
        $superadmin = User::factory()->superAdmin()->create();
        (new ConfirmWorkLog)->handle($superadmin, $log, 1);
        $this->assertSame($superadmin->id, TimeLedgerEntry::where('source_type', 'work_log')->firstOrFail()->actor_id);
        DB::table('users')->where('id', $operator->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        (new ConfirmWorkLog)->handle($operator, $log, 1);
    }

    public function test_changed_draft_requires_its_current_revision_and_ignores_caller_mutations(): void
    {
        [$request, $operator] = $this->approved();
        $log = $this->draft($operator, $request);
        $updated = (new SaveWorkLogDraft)->handle($operator, $request, ['revision' => 1,
            'worked_on' => today()->toDateString(), 'description' => '수정', 'minutes' => 30, 'is_billable' => true], $log);
        $updated->forceFill(['minutes' => 1000, 'work_request_id' => 999999]);
        $confirmed = (new ConfirmWorkLog)->handle($operator, $updated, 2);
        $this->assertSame(30, $confirmed->minutes);
        $this->assertSame(3, $confirmed->revision);
        $this->expectException(ValidationException::class);
        (new ConfirmWorkLog)->handle($operator, $log, 1);
    }

    public function test_retry_after_month_closing_returns_original_result_without_writes(): void
    {
        [$request, $operator, , $month] = $this->approved();
        $log = $this->draft($operator, $request, 60);
        $confirmed = (new ConfirmWorkLog)->handle($operator, $log, 1);
        $this->travelTo($month->month->copy()->addMonth());
        $closure = (new CloseContractMonth)->handle($operator, $month);
        $this->assertSame(60, $closure->totals['net_usage']);
        $this->assertSame(0, $closure->totals['remaining_reserved']);
        $replayed = (new ConfirmWorkLog)->handle($operator, $log, 1);
        $this->assertTrue($confirmed->confirmed_at->equalTo($replayed->confirmed_at));
        $this->assertSame(2, $replayed->revision);
        $this->assertSame(4, TimeLedgerEntry::count());
    }

    /** @return array<string, array{string}> */
    public static function failurePoints(): array
    {
        return ['second entry' => ['usage'], 'status update' => ['status']];
    }

    #[DataProvider('failurePoints')]
    public function test_failure_rolls_back_both_entries_and_status(string $point): void
    {
        [$request, $operator] = $this->approved();
        $log = $this->draft($operator, $request);
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($point, &$armed): void {
            if ($armed && (($point === 'usage' && str_starts_with($sql, 'insert into `time_ledger_entries`') && in_array('usage', $bindings, true))
                || ($point === 'status' && str_starts_with($sql, 'update `work_logs`')))) {
                throw new RuntimeException('Injected confirmation failure');
            }
        });
        try {
            (new ConfirmWorkLog)->handle($operator, $log, 1);
            $this->fail('Expected injected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected confirmation failure', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame(2, TimeLedgerEntry::count());
        $this->assertSame(WorkLogStatus::Draft, $log->fresh()->status);
        $this->assertSame(1, $log->fresh()->revision);
        (new ConfirmWorkLog)->handle($operator, $log, 1);
        $this->assertSame(4, TimeLedgerEntry::count());
    }
}
