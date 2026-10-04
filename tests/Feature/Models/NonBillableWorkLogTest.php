<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\CancelWorkLogUsage;
use App\Actions\ConfirmNonBillableWorkLog;
use App\Actions\ConfirmWorkLog;
use App\Actions\SaveWorkLogDraft;
use App\Actions\TransitionWorkRequest;
use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use App\Services\ContractMonthWorkTotals;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class NonBillableWorkLogTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return array{WorkRequest, User, User, ContractMonth} */
    private function approved(): array
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture(120, 60);
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Non-billable test');

        return [$request, $operator, $admin, $month];
    }

    private function draft(User $operator, WorkRequest $request, int $minutes = 25, ?string $workedOn = null): WorkLog
    {
        return (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => $workedOn ?? today()->toDateString(),
            'description' => '개발자 귀책 수정 작업',
            'minutes' => $minutes,
            'is_billable' => false,
            'non_billable_reason' => '최초 합의 기능의 구현 오류 수정',
        ]);
    }

    public function test_non_billable_confirmation_records_work_and_confirmer_without_touching_ledger(): void
    {
        [$request, $operator] = $this->approved();
        $log = $this->draft($operator, $request);
        $before = TimeLedgerEntry::query()->orderBy('id')->get()->toArray();

        $confirmed = (new ConfirmNonBillableWorkLog)->handle($operator, $log, 1);

        $this->assertSame(WorkLogStatus::Confirmed, $confirmed->status);
        $this->assertSame(2, $confirmed->revision);
        $this->assertSame($operator->id, $confirmed->confirmed_by);
        $this->assertSame($operator->id, $confirmed->confirmer->id);
        $this->assertSame(25, $confirmed->minutes);
        $this->assertFalse($confirmed->is_billable);
        $this->assertSame('최초 합의 기능의 구현 오류 수정', $confirmed->non_billable_reason);
        $this->assertSame($before, TimeLedgerEntry::query()->orderBy('id')->get()->toArray());
        $this->assertSame($confirmed->id, (new ConfirmNonBillableWorkLog)->handle($operator, $log, 1)->id);
        $this->assertSame($before, TimeLedgerEntry::query()->orderBy('id')->get()->toArray());
    }

    public function test_month_report_separates_total_billable_non_billable_and_actual_customer_charge(): void
    {
        [$request, $operator, , $month] = $this->approved();
        $billable = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '승인 범위 작업',
            'minutes' => 30, 'is_billable' => true,
        ]);
        (new ConfirmWorkLog)->handle($operator, $billable, 1);
        (new ConfirmNonBillableWorkLog)->handle($operator, $this->draft($operator, $request, 25), 1);

        $totals = (new ContractMonthWorkTotals)->calculate($month);
        $this->assertSame([
            'total_minutes' => 55,
            'billable_minutes' => 30,
            'non_billable_minutes' => 25,
            'customer_charged_minutes' => 30,
        ], $totals);

        (new CancelWorkLogUsage)->handle($operator, $billable, '고객 차감 취소 검증');
        $afterCancellation = (new ContractMonthWorkTotals)->calculate($month);
        $this->assertSame(55, $afterCancellation['total_minutes']);
        $this->assertSame(30, $afterCancellation['billable_minutes']);
        $this->assertSame(25, $afterCancellation['non_billable_minutes']);
        $this->assertSame(0, $afterCancellation['customer_charged_minutes']);
    }

    public function test_completed_request_can_resume_as_free_rework_and_log_time_without_new_charge(): void
    {
        [$request, $operator, $admin, $month] = $this->approved();
        $workflow = new TransitionWorkRequest;
        $workflow->handle($operator, $request, WorkRequestStatus::InProgress);
        $workflow->handle($operator, $request, WorkRequestStatus::AwaitingReview, '초기 작업 완료');
        $workflow->handle($admin, $request, WorkRequestStatus::Completed);
        $entriesAfterCompletion = TimeLedgerEntry::count();
        $rework = $workflow->handle($operator, $request, WorkRequestStatus::InProgress, '배포 후 발견된 구현 오류', true);
        $confirmed = (new ConfirmNonBillableWorkLog)->handle($operator, $this->draft($operator, $request, 40), 1);

        $this->assertTrue($rework->is_free_rework);
        $this->assertSame('배포 후 발견된 구현 오류', $rework->reason);
        $this->assertSame(WorkRequestStatus::InProgress, $request->fresh()->status);
        $this->assertSame(WorkLogStatus::Confirmed, $confirmed->status);
        $this->assertSame($entriesAfterCompletion, TimeLedgerEntry::count());
        $this->assertSame(0, (int) $month->entries()->where('type', TimeLedgerType::Usage)->sum('minutes'));
        $this->assertSame(40, (new ContractMonthWorkTotals)->calculate($month)['total_minutes']);
        $this->assertSame(0, (new ContractMonthWorkTotals)->calculate($month)['customer_charged_minutes']);
    }

    public function test_non_billable_action_rejects_billable_records_and_billable_action_rejects_non_billable_records(): void
    {
        [$request, $operator] = $this->approved();
        $billable = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '차감 작업', 'minutes' => 10, 'is_billable' => true,
        ]);
        try {
            (new ConfirmNonBillableWorkLog)->handle($operator, $billable, 1);
            $this->fail('Billable log used the non-billable workflow.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('is_billable', $error->errors());
        }
        $nonBillable = $this->draft($operator, $request);
        try {
            (new ConfirmWorkLog)->handle($operator, $nonBillable, 1);
            $this->fail('Non-billable log used the billable workflow.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('is_billable', $error->errors());
        }
        $this->assertSame(0, WorkLog::where('status', WorkLogStatus::Confirmed)->count());
    }

    public function test_terminal_request_must_be_resumed_before_non_billable_confirmation(): void
    {
        [$request, $operator] = $this->approved();
        $log = $this->draft($operator, $request);
        DB::table('work_requests')->where('id', $request->id)->update(['status' => WorkRequestStatus::Completed->value]);

        try {
            (new ConfirmNonBillableWorkLog)->handle($operator, $log, 1);
            $this->fail('Terminal request work was confirmed.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('request', $error->errors());
        }
        $this->assertSame(WorkLogStatus::Draft, $log->fresh()->status);
    }

    public function test_missing_or_closed_contract_month_blocks_confirmation(): void
    {
        [$request, $operator, , $month] = $this->approved();
        $previous = $this->draft($operator, $request, 15, today()->startOfMonth()->subMonth()->toDateString());
        try {
            (new ConfirmNonBillableWorkLog)->handle($operator, $previous, 1);
            $this->fail('Missing contract month accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('month', $error->errors());
        }

        $current = $this->draft($operator, $request);
        $month->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $operator->id])->save();
        try {
            (new ConfirmNonBillableWorkLog)->handle($operator, $current, 1);
            $this->fail('Closed contract month accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('month', $error->errors());
        }
        $this->assertSame(0, WorkLog::where('status', WorkLogStatus::Confirmed)->count());
    }

    public function test_permissions_revision_and_caller_mutations_are_rechecked(): void
    {
        [$request, $operator, $admin] = $this->approved();
        $log = $this->draft($operator, $request);
        foreach ([$admin, User::factory()->operator()->create()] as $actor) {
            try {
                (new ConfirmNonBillableWorkLog)->handle($actor, $log, 1);
                $this->fail('Unauthorized actor confirmed non-billable work.');
            } catch (AuthorizationException) {
                $this->assertSame(WorkLogStatus::Draft, $log->fresh()->status);
            }
        }
        $log->forceFill(['minutes' => 999, 'work_request_id' => 999999]);
        try {
            (new ConfirmNonBillableWorkLog)->handle($operator, $log, 0);
            $this->fail('Stale revision accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('revision', $error->errors());
        }
        $superAdmin = User::factory()->superAdmin()->create();
        $confirmed = (new ConfirmNonBillableWorkLog)->handle($superAdmin, $log, 1);
        $this->assertSame(25, $confirmed->minutes);
        $this->assertSame($request->id, $confirmed->work_request_id);
        $this->assertSame($superAdmin->id, $confirmed->confirmed_by);
    }

    public function test_deactivated_worker_cannot_confirm_non_billable_work(): void
    {
        [$request, $operator] = $this->approved();
        $log = $this->draft($operator, $request);
        User::query()->whereKey($operator->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        (new ConfirmNonBillableWorkLog)->handle($operator, $log, 1);
    }

    public function test_confirmation_failure_rolls_back_status_and_confirmer(): void
    {
        [$request, $operator] = $this->approved();
        $log = $this->draft($operator, $request);
        $ledger = TimeLedgerEntry::count();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with($sql, 'update `work_logs`')) {
                throw new RuntimeException('Injected non-billable confirmation failure');
            }
        });
        try {
            (new ConfirmNonBillableWorkLog)->handle($operator, $log, 1);
            $this->fail('Expected confirmation failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected non-billable confirmation failure', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame(WorkLogStatus::Draft, $log->fresh()->status);
        $this->assertNull($log->fresh()->confirmed_at);
        $this->assertNull($log->fresh()->confirmed_by);
        $this->assertSame(1, $log->fresh()->revision);
        $this->assertSame($ledger, TimeLedgerEntry::count());
    }
}
