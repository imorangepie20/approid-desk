<?php

namespace Tests\Feature;

use App\Actions\RetryNotificationDelivery;
use App\Actions\SendWeeklyProgressReports;
use App\Enums\CompanyStatus;
use App\Enums\NotificationDeliveryFailure;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WeeklyProgressReport;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use App\Notifications\BusinessNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class WeeklyProgressReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_snapshots_the_previous_week_and_queues_each_authorized_recipient(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-05 09:15:00');
        $company = Company::factory()->create();
        $operator = User::factory()->operator()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $customerAdmin = User::factory()->customerAdmin()->for($company)->create();
        User::factory()->customerUser()->for($company)->create();
        User::factory()->customerAdmin()->for(Company::factory())->create();
        User::factory()->customerAdmin()->inactive()->for($company)->create();

        $received = $this->request($company, WorkRequestStatus::Received, '2026-10-01 10:00:00');
        $inProgress = $this->request($company, WorkRequestStatus::InProgress, '2026-09-01 10:00:00');
        $completed = $this->request($company, WorkRequestStatus::Completed, '2026-09-01 10:00:00');
        $this->statusChange($inProgress, $operator, WorkRequestStatus::Queued, WorkRequestStatus::InProgress, '2026-10-02 11:00:00');
        $this->statusChange($completed, $operator, WorkRequestStatus::AwaitingReview, WorkRequestStatus::Completed, '2026-10-03 12:00:00');

        $this->assertSame(1, (new SendWeeklyProgressReports)->handle(today()));

        $report = WeeklyProgressReport::query()->sole();
        $this->assertSame($company->id, $report->company_id);
        $this->assertSame('2026-09-28', $report->period_start->toDateString());
        $this->assertSame('2026-10-04', $report->period_end->toDateString());
        $this->assertSame(2, $report->open_request_count);
        $this->assertSame(1, $report->new_request_count);
        $this->assertSame(2, $report->changed_request_count);
        $this->assertSame(1, $report->completed_request_count);
        $this->assertSame(3, $report->recipient_count);
        $this->assertSame(1, $report->status_counts[WorkRequestStatus::Received->value]);
        $this->assertSame(1, $report->status_counts[WorkRequestStatus::InProgress->value]);
        $this->assertSame(0, $report->status_counts[WorkRequestStatus::AwaitingApproval->value]);

        $this->assertDatabaseCount('notification_deliveries', 6);
        $this->assertEqualsCanonicalizing(
            [$customerAdmin->id, $operator->id, $superAdmin->id],
            NotificationDelivery::query()->distinct()->orderBy('notifiable_id')->pluck('notifiable_id')->all(),
        );
        $this->assertTrue(NotificationDelivery::query()->get()->every(
            fn (NotificationDelivery $delivery): bool => $delivery->work_request_id === null
                && $delivery->weekly_progress_report_id === $report->id
                && $delivery->notification_type === NotificationType::WeeklyProgressReport,
        ));
        Queue::assertPushed(SendQueuedNotifications::class, 6);
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($customerAdmin, $report): bool {
            if ($job->channels !== ['mail'] || ! $job->notifiables->sole()->is($customerAdmin)) {
                return false;
            }

            $notification = $job->notification;

            return $notification instanceof BusinessNotification
                && $notification->type === NotificationType::WeeklyProgressReport
                && $notification->workRequestId === null
                && $notification->context['weekly_report_id'] === $report->id
                && $notification->actionUrl === '/dashboard'
                && $notification->toDatabase($customerAdmin)['completed_request_count'] === 1
                && $notification->shouldSend($customerAdmin, 'mail');
        });
    }

    public function test_same_company_and_week_are_generated_only_once(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-05 09:15:00');
        $company = Company::factory()->create();
        User::factory()->customerAdmin()->for($company)->create();
        $this->request($company, WorkRequestStatus::Received, '2026-10-01 10:00:00');
        $sender = new SendWeeklyProgressReports;

        $this->assertSame(1, $sender->handle(today()));
        $this->assertSame(0, $sender->handle(today()->addDays(2)));
        $this->assertDatabaseCount('weekly_progress_reports', 1);
        $this->assertDatabaseCount('notification_deliveries', 2);
        Queue::assertPushed(SendQueuedNotifications::class, 2);
    }

    public function test_it_skips_inactive_idle_and_recipientless_companies_but_includes_recent_completion(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-05 09:15:00');
        $actor = User::factory()->operator()->inactive()->create();

        $inactive = Company::factory()->create(['status' => CompanyStatus::Inactive]);
        User::factory()->customerAdmin()->for($inactive)->create();
        $this->request($inactive, WorkRequestStatus::Received, '2026-10-01 10:00:00');

        $idle = Company::factory()->create();
        User::factory()->customerAdmin()->for($idle)->create();
        $this->request($idle, WorkRequestStatus::Completed, '2026-09-01 10:00:00');

        $recipientless = Company::factory()->create();
        $this->request($recipientless, WorkRequestStatus::Received, '2026-10-01 10:00:00');

        $recent = Company::factory()->create();
        User::factory()->customerAdmin()->for($recent)->create();
        $completed = $this->request($recent, WorkRequestStatus::Completed, '2026-09-01 10:00:00');
        $this->statusChange($completed, $actor, WorkRequestStatus::AwaitingReview, WorkRequestStatus::Completed, '2026-10-04 23:59:59');

        $this->assertSame(1, (new SendWeeklyProgressReports)->handle(today()));
        $this->assertSame($recent->id, WeeklyProgressReport::query()->sole()->company_id);
        $this->assertSame(1, WeeklyProgressReport::query()->sole()->completed_request_count);
    }

    public function test_delivery_rechecks_current_report_access(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-05 09:15:00');
        $company = Company::factory()->create();
        $admin = User::factory()->customerAdmin()->for($company)->create();
        $this->request($company, WorkRequestStatus::Received, '2026-10-01 10:00:00');
        (new SendWeeklyProgressReports)->handle(today());
        $job = null;
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $queued) use ($admin, &$job): bool {
            if ($queued->channels === ['mail'] && $queued->notifiables->sole()->is($admin)) {
                $job = $queued;

                return true;
            }

            return false;
        });
        $this->assertInstanceOf(SendQueuedNotifications::class, $job);
        $this->assertTrue($job->notification->shouldSend($admin, 'mail'));

        $admin->forceFill(['role' => UserRole::CustomerUser])->save();
        $this->assertFalse($job->notification->shouldSend($admin, 'mail'));
        $admin->forceFill(['role' => UserRole::CustomerAdmin])->save();
        $company->update(['status' => CompanyStatus::Inactive]);
        $this->assertFalse($job->notification->shouldSend($admin, 'mail'));
    }

    public function test_failed_weekly_delivery_can_use_the_existing_audited_retry_path(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-05 09:15:00');
        $company = Company::factory()->create();
        $operator = User::factory()->operator()->create();
        $admin = User::factory()->customerAdmin()->for($company)->create();
        $this->request($company, WorkRequestStatus::Received, '2026-10-01 10:00:00');
        (new SendWeeklyProgressReports)->handle(today());
        $delivery = NotificationDelivery::query()
            ->where('notifiable_id', $admin->id)->where('channel', 'mail')->sole();
        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Failed,
            'failed_at' => now(),
            'failure_code' => NotificationDeliveryFailure::Unexpected,
        ])->save();
        $this->actingAs($operator)->get(route('notification-deliveries.index'))
            ->assertOk()
            ->assertSee('주간 진행 보고')
            ->assertSee($company->name)
            ->assertSee('2026.09.28-10.04');
        Queue::fake();

        (new RetryNotificationDelivery)->handle($operator, $delivery);

        $this->assertSame(NotificationDeliveryStatus::Pending, $delivery->fresh()->status);
        $this->assertDatabaseHas('notification_delivery_retries', [
            'notification_delivery_id' => $delivery->id,
            'requested_by' => $operator->id,
        ]);
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($delivery): bool {
            return $job->channels === ['mail']
                && $job->notification instanceof BusinessNotification
                && $job->notification->workRequestId === null
                && $job->notification->deliveryIds === ['mail' => $delivery->id];
        });
    }

    public function test_report_is_immutable_in_the_model_and_database(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-05 09:15:00');
        $company = Company::factory()->create();
        User::factory()->customerAdmin()->for($company)->create();
        $this->request($company, WorkRequestStatus::Received, '2026-10-01 10:00:00');
        (new SendWeeklyProgressReports)->handle(today());
        $report = WeeklyProgressReport::query()->sole();

        try {
            $report->forceFill(['open_request_count' => 999])->save();
            $this->fail('Weekly progress reports must be immutable.');
        } catch (LogicException $exception) {
            $this->assertSame('감사 기록은 수정할 수 없습니다.', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('weekly_progress_reports')->where('id', $report->id)->delete();
    }

    public function test_database_rejects_a_delivery_with_mixed_or_missing_scope(): void
    {
        $request = WorkRequest::factory()->create();
        $recipient = User::factory()->customerAdmin()->for($request->company)->create();

        $this->expectException(QueryException::class);
        DB::table('notification_deliveries')->insert([
            'id' => fake()->uuid(),
            'event_key' => hash('sha256', 'invalid-weekly-scope'),
            'company_id' => $request->company_id,
            'work_request_id' => $request->id,
            'weekly_progress_report_id' => null,
            'notification_type' => NotificationType::WeeklyProgressReport->value,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id,
            'channel' => 'database',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_console_command_and_monday_schedule_are_registered(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-05 09:15:00');
        $company = Company::factory()->create();
        User::factory()->customerAdmin()->for($company)->create();
        $this->request($company, WorkRequestStatus::Received, '2026-10-01 10:00:00');

        $this->artisan('desk:send-weekly-progress-reports')
            ->expectsOutput('주간 진행 보고 1건을 생성하고 발송 대기열에 등록했습니다.')
            ->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'desk:send-weekly-progress-reports'));
        $this->assertNotNull($event);
        $this->assertSame('15 9 * * 1', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    private function request(Company $company, WorkRequestStatus $status, string $registeredAt): WorkRequest
    {
        $request = WorkRequest::factory()->for($company)->create([
            'status' => WorkRequestStatus::Received,
            'requested_at' => $registeredAt,
            'registered_at' => $registeredAt,
        ]);
        if ($status !== WorkRequestStatus::Received) {
            DB::table('work_requests')->where('id', $request->id)->update(['status' => $status->value]);
            $request->refresh();
        }

        return $request;
    }

    private function statusChange(
        WorkRequest $request,
        User $actor,
        WorkRequestStatus $from,
        WorkRequestStatus $to,
        string $occurredAt,
    ): WorkRequestStatusChange {
        $change = new WorkRequestStatusChange;
        $change->forceFill([
            'company_id' => $request->company_id,
            'work_request_id' => $request->id,
            'changed_by' => $actor->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => null,
            'estimate_version_id' => null,
            'is_free_rework' => false,
            'occurred_at' => $occurredAt,
        ])->save();

        return $change;
    }
}
