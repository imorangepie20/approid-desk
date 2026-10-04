<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\ConfirmWorkLog;
use App\Actions\SaveWorkLogDraft;
use App\Actions\TransitionWorkRequest;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class TerminalReservationReleaseTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return array{WorkRequest, User, User, ContractMonth} */
    private function approved(): array
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Terminal release test');

        return [$request, $operator, $admin, $month];
    }

    private function draft(WorkRequest $request, User $operator, int $minutes): WorkLog
    {
        return (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '종결 정산 검증',
            'minutes' => $minutes, 'is_billable' => true,
        ]);
    }

    public function test_completion_releases_only_remaining_reservation_and_preserves_usage(): void
    {
        [$request, $operator, $admin, $month] = $this->approved();
        $log = $this->draft($request, $operator, 45);
        (new ConfirmWorkLog)->handle($operator, $log, 1);
        $action = new TransitionWorkRequest;
        $action->handle($operator, $request, WorkRequestStatus::InProgress);
        $action->handle($operator, $request, WorkRequestStatus::AwaitingReview, '작업 완료');
        $change = $action->handle($admin, $request, WorkRequestStatus::Completed);

        $this->assertSame(WorkRequestStatus::Completed, $request->fresh()->status);
        $terminalRelease = TimeLedgerEntry::where('source_type', 'work_request_status_change')->sole();
        $this->assertSame($change->id, $terminalRelease->source_id);
        $this->assertSame(TimeLedgerType::Release, $terminalRelease->type);
        $this->assertSame(15, $terminalRelease->minutes);
        $this->assertSame($admin->id, $terminalRelease->actor_id);
        $this->assertSame('요청 완료 잔여 예약 해제', $terminalRelease->reason);
        $this->assertTrue($change->occurred_at->equalTo($terminalRelease->occurred_at));
        $this->assertSame(45, (int) $month->entries()->where('type', TimeLedgerType::Usage)->sum('minutes'));
        $this->assertSame(0, $month->entries()->where('type', TimeLedgerType::CancelUsage)->count());
        $this->assertSame(60, (int) $month->entries()->where('type', TimeLedgerType::Release)->sum('minutes'));
        $this->assertSame(55, $month->entries()->get()->sum(
            fn (TimeLedgerEntry $entry): int => $entry->minutes * $entry->type->availableSign()
        ));
    }

    public function test_cancellation_releases_entire_reservation(): void
    {
        [$request, $operator, , $month] = $this->approved();
        $change = (new TransitionWorkRequest)->handle($operator, $request, WorkRequestStatus::Cancelled, '고객 요청 취소');

        $entry = TimeLedgerEntry::where('source_type', 'work_request_status_change')->sole();
        $this->assertSame(WorkRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame($change->id, $entry->source_id);
        $this->assertSame(60, $entry->minutes);
        $this->assertSame('요청 취소 잔여 예약 해제', $entry->reason);
        $this->assertSame(100, $month->entries()->get()->sum(
            fn (TimeLedgerEntry $ledger): int => $ledger->minutes * $ledger->type->availableSign()
        ));
    }

    public function test_terminal_transition_without_reservation_creates_no_fake_ledger(): void
    {
        $request = WorkRequest::factory()->create();
        (new TransitionWorkRequest)->handle(User::factory()->operator()->create(),
            $request, WorkRequestStatus::Cancelled, '접수 취소');
        $this->assertSame(WorkRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame(0, TimeLedgerEntry::count());
    }

    public function test_fully_used_reservation_needs_no_additional_terminal_release(): void
    {
        [$request, $operator, $admin, $month] = $this->approved();
        (new ConfirmWorkLog)->handle($operator, $this->draft($request, $operator, 60), 1);
        $action = new TransitionWorkRequest;
        $action->handle($operator, $request, WorkRequestStatus::InProgress);
        $action->handle($operator, $request, WorkRequestStatus::AwaitingReview, '작업 완료');
        $action->handle($admin, $request, WorkRequestStatus::Completed);
        $this->assertSame(0, TimeLedgerEntry::where('source_type', 'work_request_status_change')->count());
        $this->assertSame(60, (int) $month->entries()->where('type', TimeLedgerType::Usage)->sum('minutes'));
        $this->assertSame(0, (int) $month->entries()->where('type', TimeLedgerType::CancelUsage)->sum('minutes'));
    }

    public function test_release_failure_rolls_back_status_history_activity_and_ledger(): void
    {
        [$request, $operator] = $this->approved();
        $history = $request->statusChanges()->count();
        $activities = $request->activities()->count();
        $entries = TimeLedgerEntry::count();
        TimeLedgerEntry::creating(function (TimeLedgerEntry $entry): void {
            if ($entry->source_type === 'work_request_status_change') {
                throw new RuntimeException('Injected terminal release failure');
            }
        });
        try {
            (new TransitionWorkRequest)->handle($operator, $request, WorkRequestStatus::Cancelled, '취소');
            $this->fail('Expected release failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected terminal release failure', $error->getMessage());
        }
        $this->assertSame(WorkRequestStatus::Queued, $request->fresh()->status);
        $this->assertSame($history, WorkRequestStatusChange::count());
        $this->assertSame($activities, $request->activities()->count());
        $this->assertSame($entries, TimeLedgerEntry::count());
    }
}
