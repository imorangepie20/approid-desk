<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\CloseContractMonth;
use App\Actions\ConfirmWorkLog;
use App\Actions\ProvideContractMonth;
use App\Actions\ReleaseMonthTransitionReservation;
use App\Actions\SaveWorkLogDraft;
use App\Actions\SendMonthTransitionReminders;
use App\Actions\TransitionWorkRequest;
use App\Enums\NotificationType;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\MonthTransitionNotice;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class MonthTransitionTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return array{WorkRequest, User, User, ContractMonth} */
    private function approvedFixture(int $budget = 100, int $minutes = 60): array
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture($budget, $minutes);
        (new ApproveEstimateVersion)->handle(
            $admin,
            $estimate,
            (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT,
            '127.0.0.1',
            'Month transition test',
        );
        DB::table('notifications')->delete();

        return [$request, $operator, $admin, $month];
    }

    public function test_scheduler_notifies_only_active_eligible_recipients_once_without_charging_next_month(): void
    {
        [$request, $operator, $admin, $month] = $this->approvedFixture();
        $superAdmin = User::factory()->superAdmin()->create();
        $customer = User::factory()->customerUser()->for($request->company)->create();
        $otherAdmin = User::factory()->customerAdmin()->create();
        $inactiveOperator = User::factory()->operator()->inactive()->create();
        $approvedEstimate = $request->fresh()->approved_estimate_version_id;
        $ledgerCount = TimeLedgerEntry::count();
        $this->travelTo($month->month->copy()->endOfMonth()->subDays(2)->setTime(9, 0));

        $this->assertSame(1, (new SendMonthTransitionReminders)->handle(today()));

        $notice = MonthTransitionNotice::sole();
        $this->assertSame($request->company_id, $notice->company_id);
        $this->assertSame($month->id, $notice->contract_month_id);
        $this->assertSame($request->id, $notice->work_request_id);
        $this->assertSame(60, $notice->remaining_minutes);
        $expectedRecipients = User::query()->where('is_active', true)->get()
            ->filter(fn (User $user): bool => $user->canAccessWorkspace()
                && ($user->role->isSystemRole() || ($user->id === $admin->id)))
            ->pluck('id')->sort()->values()->all();
        $this->assertSame(count($expectedRecipients), $notice->recipient_count);
        $recipients = DB::table('notifications')->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame($expectedRecipients, $recipients);
        $this->assertContains($operator->id, $recipients);
        $this->assertContains($admin->id, $recipients);
        $this->assertContains($superAdmin->id, $recipients);
        $this->assertNotContains($customer->id, $recipients);
        $this->assertNotContains($otherAdmin->id, $recipients);
        $this->assertNotContains($inactiveOperator->id, $recipients);
        $payload = json_decode((string) DB::table('notifications')->value('data'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(NotificationType::MonthTransitionNeeded->value, $payload['kind']);
        $this->assertSame($request->id, $payload['work_request_id']);
        $this->assertSame(60, $payload['remaining_minutes']);
        $this->assertSame($ledgerCount, TimeLedgerEntry::count());
        $this->assertSame(0, TimeLedgerEntry::where('source_type', 'month_transition_notice')->count());
        $this->assertSame($approvedEstimate, $request->fresh()->approved_estimate_version_id);
        $this->assertSame(WorkRequestStatus::Queued, $request->fresh()->status);
        $this->assertSame(0, (new SendMonthTransitionReminders)->handle(today()));
        $this->assertSame(1, MonthTransitionNotice::count());
        $this->assertSame(count($expectedRecipients), DB::table('notifications')->count());
    }

    public function test_candidates_are_not_notified_before_the_lead_window_or_after_terminal_status(): void
    {
        [$request, , , $month] = $this->approvedFixture();
        $this->travelTo($month->month->copy()->startOfMonth()->addDays(10));
        $this->assertSame(0, (new SendMonthTransitionReminders)->handle(today()));

        DB::table('work_requests')->where('id', $request->id)->update(['status' => WorkRequestStatus::Completed->value]);
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());
        $this->assertSame(0, (new SendMonthTransitionReminders)->handle(today()));
        $this->assertSame(0, MonthTransitionNotice::count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_explicit_month_end_release_is_idempotent_preserves_request_and_allows_closing(): void
    {
        [$request, $operator, , $month] = $this->approvedFixture();
        $approvedEstimate = $request->fresh()->approved_estimate_version_id;
        $this->travelTo($month->month->copy()->endOfMonth()->subDays(2));
        (new SendMonthTransitionReminders)->handle(today());
        $notice = MonthTransitionNotice::sole();

        try {
            (new ReleaseMonthTransitionReservation)->handle($operator, $notice, '다음 달 재승인 예정');
            $this->fail('Reservation released before month end.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('month', $error->errors());
        }

        $this->travelTo($month->month->copy()->endOfMonth()->setTime(9, 0));
        $release = (new ReleaseMonthTransitionReservation)->handle($operator, $notice, '다음 달 재승인 예정');
        $this->assertSame(TimeLedgerType::Release, $release->type);
        $this->assertSame(60, $release->minutes);
        $this->assertSame('month_transition_notice', $release->source_type);
        $this->assertSame($notice->id, $release->source_id);
        $this->assertSame($release->id, (new ReleaseMonthTransitionReservation)->handle($operator, $notice, '다음 달 재승인 예정')->id);
        $this->assertSame(1, TimeLedgerEntry::where('source_type', 'month_transition_notice')->count());
        $this->assertSame($approvedEstimate, $request->fresh()->approved_estimate_version_id);
        $this->assertSame(WorkRequestStatus::Queued, $request->fresh()->status);

        $this->travelTo($month->month->copy()->addMonth());
        $closure = (new CloseContractMonth)->handle($operator, $month);
        $this->assertSame(0, $closure->totals['remaining_reserved']);
        $this->assertSame(100, $closure->totals['available']);
    }

    public function test_partial_usage_releases_only_the_unused_balance(): void
    {
        [$request, $operator, , $month] = $this->approvedFixture(100, 60);
        $log = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(),
            'description' => '월말 전 확정 작업',
            'minutes' => 40,
            'is_billable' => true,
        ]);
        (new ConfirmWorkLog)->handle($operator, $log, 1);
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());
        (new SendMonthTransitionReminders)->handle(today());
        $notice = MonthTransitionNotice::sole();
        $this->assertSame(20, $notice->remaining_minutes);

        $this->travelTo($month->month->copy()->endOfMonth());
        $release = (new ReleaseMonthTransitionReservation)->handle($operator, $notice, '미사용 예약 반환');
        $this->assertSame(20, $release->minutes);
        $this->assertSame(40, (int) TimeLedgerEntry::where('type', TimeLedgerType::Usage)->sum('minutes'));
        $this->assertSame(60, (int) TimeLedgerEntry::where('type', TimeLedgerType::Release)->sum('minutes'));
        $this->assertSame(60, (int) TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->sum('minutes'));
    }

    public function test_continuing_next_month_requires_a_new_estimate_approval(): void
    {
        [$request, $operator, $admin, $month] = $this->approvedFixture(100, 60);
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());
        (new SendMonthTransitionReminders)->handle(today());
        $notice = MonthTransitionNotice::sole();
        $this->assertSame(0, TimeLedgerEntry::where('contract_month_id', '!=', $month->id)->where('type', TimeLedgerType::Reserve)->count());

        $this->travelTo($month->month->copy()->endOfMonth());
        (new ReleaseMonthTransitionReservation)->handle($operator, $notice, '다음 달 계속 작업');
        $nextMonth = (new ProvideContractMonth)->handle(
            $operator,
            $request->serviceContract,
            $month->month->copy()->addMonth()->toDateString(),
            100,
        );
        $replacement = $this->timeEstimate($operator, $request, 80, 1);
        (new TransitionWorkRequest)->handle($operator, $request, WorkRequestStatus::AwaitingApproval);
        $this->assertSame(0, TimeLedgerEntry::where('contract_month_id', $nextMonth->id)->where('type', TimeLedgerType::Reserve)->count());

        (new ApproveEstimateVersion)->handle(
            $admin,
            $replacement,
            (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT,
            '127.0.0.1',
            'Next month approval test',
        );
        $this->assertSame(80, TimeLedgerEntry::where('contract_month_id', $nextMonth->id)
            ->where('type', TimeLedgerType::Reserve)->sole()->minutes);
        $this->assertSame(60, TimeLedgerEntry::where('contract_month_id', $month->id)
            ->where('source_type', 'month_transition_notice')->sole()->minutes);
        $this->assertSame(0, TimeLedgerEntry::where('source_type', 'estimate_replacement')->count());
        $this->assertSame($replacement->id, $request->fresh()->approved_estimate_version_id);
    }

    public function test_release_requires_month_management_permission_and_exact_retry_payload(): void
    {
        [, $operator, $admin, $month] = $this->approvedFixture();
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());
        (new SendMonthTransitionReminders)->handle(today());
        $notice = MonthTransitionNotice::sole();
        $this->travelTo($month->month->copy()->endOfMonth());

        try {
            (new ReleaseMonthTransitionReservation)->handle($admin, $notice, '고객 직접 반환');
            $this->fail('Customer released a reservation.');
        } catch (AuthorizationException) {
            $this->assertSame(0, TimeLedgerEntry::where('source_type', 'month_transition_notice')->count());
        }

        (new ReleaseMonthTransitionReservation)->handle($operator, $notice, '운영자 반환');
        $other = User::factory()->superAdmin()->create();
        try {
            (new ReleaseMonthTransitionReservation)->handle($other, $notice, '다른 재전송');
            $this->fail('Changed idempotent payload accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('notice', $error->errors());
        }
        $this->assertSame(1, TimeLedgerEntry::where('source_type', 'month_transition_notice')->count());
    }

    public function test_notification_failure_after_commit_preserves_notice_and_never_changes_the_ledger(): void
    {
        [, , , $month] = $this->approvedFixture();
        $ledgerCount = TimeLedgerEntry::count();
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with($sql, 'insert into `notifications`')) {
                throw new RuntimeException('Injected notification failure');
            }
        });

        try {
            (new SendMonthTransitionReminders)->handle(today());
            $this->fail('Expected notification failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected notification failure', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame(1, MonthTransitionNotice::count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame($ledgerCount, TimeLedgerEntry::count());
    }

    public function test_no_notice_is_written_when_there_are_no_active_recipients(): void
    {
        [, $operator, $admin, $month] = $this->approvedFixture();
        User::query()->update(['is_active' => false]);
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());

        $this->assertSame(0, (new SendMonthTransitionReminders)->handle(today()));
        $this->assertSame(0, MonthTransitionNotice::count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_transition_notices_are_immutable_in_the_model_and_database(): void
    {
        [, , , $month] = $this->approvedFixture();
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());
        (new SendMonthTransitionReminders)->handle(today());
        $notice = MonthTransitionNotice::sole();

        try {
            $notice->forceFill(['remaining_minutes' => 1])->save();
            $this->fail('Notice updated through model.');
        } catch (LogicException) {
            $this->assertSame(60, $notice->fresh()->remaining_minutes);
        }
        $this->expectException(QueryException::class);
        DB::table('month_transition_notices')->where('id', $notice->id)->delete();
    }

    public function test_database_rejects_a_notice_linked_to_another_requests_reservation(): void
    {
        [$request, , , $month] = $this->approvedFixture();
        $otherRequest = WorkRequest::factory()->for($request->company)->create([
            'service_contract_id' => $request->service_contract_id,
        ]);
        $reserve = TimeLedgerEntry::where('work_request_id', $request->id)
            ->where('type', TimeLedgerType::Reserve)->sole();

        $this->expectException(QueryException::class);
        DB::table('month_transition_notices')->insert([
            'company_id' => $request->company_id,
            'contract_month_id' => $month->id,
            'work_request_id' => $otherRequest->id,
            'reserve_entry_id' => $reserve->id,
            'remaining_minutes' => 60,
            'detected_for' => today()->toDateString(),
            'recipient_count' => 1,
            'notified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_console_command_and_daily_schedule_are_registered(): void
    {
        [, , , $month] = $this->approvedFixture();
        $this->travelTo($month->month->copy()->endOfMonth()->subDay()->setTime(9, 0));
        $this->assertSame(0, Artisan::call('desk:notify-month-transitions'));
        $this->assertStringContainsString('월 전환 알림 대상 1건을 처리했습니다.', Artisan::output());
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'desk:notify-month-transitions'));
        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
    }

    public function test_header_shows_only_the_users_unread_notice_and_marks_it_read_before_redirecting(): void
    {
        [$request, $operator, $admin, $month] = $this->approvedFixture();
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());
        (new SendMonthTransitionReminders)->handle(today());
        $ownNotification = $admin->fresh()->unreadNotifications()->sole();
        $otherNotification = $operator->fresh()->unreadNotifications()->firstOrFail();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('읽지 않은 알림 1개')
            ->assertSee(e($request->title), false)
            ->assertSee('남은 예약 60분')
            ->assertDontSee($otherNotification->id);

        $this->actingAs($admin)->post(route('notifications.read', $ownNotification->id))
            ->assertRedirect(route('requests.show', $request));
        $this->assertNotNull($ownNotification->fresh()->read_at);
        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()->assertSee('새 알림이 없습니다.');
        $this->actingAs($admin)->post(route('notifications.read', $otherNotification->id))->assertNotFound();
    }
}
