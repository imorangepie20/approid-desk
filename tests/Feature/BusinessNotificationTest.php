<?php

namespace Tests\Feature;

use App\Actions\ApproveEstimateVersion;
use App\Actions\SendBusinessNotification;
use App\Actions\TransitionWorkRequest;
use App\Enums\IntakeChannel;
use App\Enums\NotificationType;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Jobs\ScanAttachment;
use App\Models\NotificationDelivery;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use App\Notifications\BusinessNotification;
use App\Queue\Middleware\RecordNotificationDelivery;
use App\Services\NotificationRecipients;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class BusinessNotificationTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    public function test_request_creation_dispatches_normal_or_urgent_type_to_operations(): void
    {
        Notification::fake();
        $operator = User::factory()->operator()->create();
        $customer = User::factory()->customerUser()->create();
        $project = Project::factory()->for($customer->company)->create();

        $this->actingAs($customer)->post(route('requests.store'), [
            'project_id' => $project->id,
            'title' => '긴급 알림 연결 확인',
            'requirements' => '요청 원문은 알림에 포함하지 않습니다.',
            'type' => WorkRequestType::BugFix->value,
            'priority' => WorkRequestPriority::High->value,
            'is_urgent' => 1,
            'desired_due_date' => today()->addWeek()->toDateString(),
            'intake_channel' => IntakeChannel::Web->value,
        ])->assertRedirect();

        Notification::assertSentTo($operator, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::UrgentRequestCreated);
    }

    public function test_recipient_resolution_deduplicates_excludes_actor_and_enforces_visibility(): void
    {
        $submitter = User::factory()->customerUser()->create();
        $request = WorkRequest::factory()->for($submitter->company)->create([
            'submitted_by' => $submitter->id,
        ]);
        $assignee = User::factory()->operator()->create();
        $request->withoutEvents(fn () => $request->update(['assigned_to' => $assignee->id]));
        $commenter = User::factory()->customerUser()->for($request->company)->create();
        WorkRequestComment::withoutEvents(fn () => $request->comments()->create([
            'company_id' => $request->company_id,
            'author_id' => $commenter->id,
            'body' => '수신자 확인용 댓글',
        ]));
        $inactive = User::factory()->customerUser()->inactive()->for($request->company)->create();
        WorkRequestComment::withoutEvents(fn () => $request->comments()->create([
            'company_id' => $request->company_id,
            'author_id' => $inactive->id,
            'body' => '비활성 사용자 댓글',
        ]));
        $otherCompanyUser = User::factory()->customerUser()->create();
        WorkRequestComment::withoutEvents(fn () => $request->comments()->create([
            'company_id' => $request->company_id,
            'author_id' => $otherCompanyUser->id,
            'body' => '잘못 연결된 타 고객사 사용자 댓글',
        ]));

        $recipients = (new NotificationRecipients)->for(
            NotificationType::CommentCreated,
            $request,
            $commenter,
        );

        $this->assertSame([$submitter->id, $assignee->id], $recipients->pluck('id')->all());
    }

    public function test_important_notification_queues_database_and_mail_after_commit(): void
    {
        Queue::fake();
        $request = WorkRequest::factory()->create();
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $operator = User::factory()->operator()->create();

        $count = (new SendBusinessNotification)->handle(
            NotificationType::EstimateSubmitted,
            $request,
            $operator,
            ['estimate_version_id' => 41],
        );

        $this->assertSame(1, $count);
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($admin): bool {
            return $job->afterCommit === true
                && $job->channels === ['database']
                && $job->notifiables->sole()->is($admin)
                && $job->notification instanceof BusinessNotification
                && $job->tries === BusinessNotification::MAX_ATTEMPTS
                && $job->timeout === BusinessNotification::TIMEOUT_SECONDS
                && $job->failOnTimeout
                && $job->backoff() === BusinessNotification::RETRY_DELAYS_SECONDS;
        });
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return $job->afterCommit === true && $job->channels === ['mail'];
        });
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, new BusinessNotification(
            NotificationType::EstimateSubmitted,
            $request->company_id,
            $request->id,
            $request->title,
            '검토할 새 견적이 제출되었습니다.',
            '/requests/'.$request->id,
        ));
    }

    public function test_database_queue_retry_window_exceeds_every_job_timeout(): void
    {
        $scan = new ScanAttachment(1);
        $retryAfter = config('queue.connections.database.retry_after');

        $this->assertIsInt($retryAfter);
        $this->assertGreaterThan(BusinessNotification::TIMEOUT_SECONDS, $retryAfter);
        $this->assertGreaterThan($scan->timeout, $retryAfter);
        $this->assertLessThan(BusinessNotification::TIMEOUT_SECONDS, config('mail.mailers.smtp.timeout'));
        $this->assertSame(4, BusinessNotification::MAX_ATTEMPTS);
        $this->assertSame([60, 300, 900], BusinessNotification::RETRY_DELAYS_SECONDS);
        $this->assertSame(3, $scan->tries);
        $this->assertSame([60, 300], $scan->backoff());
    }

    public function test_same_business_event_is_queued_once_per_recipient_and_channel(): void
    {
        Queue::fake();
        $request = WorkRequest::factory()->create();
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $operator = User::factory()->operator()->create();
        $dispatcher = new SendBusinessNotification;

        $first = $dispatcher->handle(
            NotificationType::EstimateSubmitted,
            $request,
            $operator,
            ['estimate_version_id' => 71],
        );
        $second = $dispatcher->handle(
            NotificationType::EstimateSubmitted,
            $request,
            $operator,
            ['estimate_version_id' => 71],
        );

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertDatabaseCount('notification_deliveries', 2);
        $this->assertSame(1, NotificationDelivery::query()->distinct()->count('event_key'));
        $this->assertEqualsCanonicalizing(
            ['database', 'mail'],
            NotificationDelivery::query()->pluck('channel')->map->value->all(),
        );
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($admin): bool {
            $channel = $job->channels[0];
            $notification = $job->notification;

            return $notification instanceof BusinessNotification
                && $job->notifiables->sole()->is($admin)
                && array_key_exists($channel, $notification->deliveryIds)
                && collect($job->middleware)->contains(
                    fn (object $middleware): bool => $middleware instanceof RecordNotificationDelivery
                        && $middleware->deliveryId === $notification->deliveryIds[$channel]
                        && $middleware->channel === $channel,
                );
        });
    }

    public function test_delivery_record_allows_failures_to_retry_and_suppresses_completed_runs(): void
    {
        $request = WorkRequest::factory()->create();
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $delivery = NotificationDelivery::query()->create([
            'event_key' => hash('sha256', 'retryable-delivery'),
            'company_id' => $request->company_id,
            'work_request_id' => $request->id,
            'notification_type' => NotificationType::EstimateSubmitted->value,
            'notifiable_type' => $admin->getMorphClass(),
            'notifiable_id' => $admin->id,
            'channel' => 'mail',
        ]);
        $middleware = new RecordNotificationDelivery($delivery->id, 'mail');

        try {
            $middleware->handle(new \stdClass, static fn () => throw new \RuntimeException('mail transport failed'));
            $this->fail('A channel exception should be propagated for the queue retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('mail transport failed', $exception->getMessage());
        }

        $this->assertNull($delivery->fresh()->sent_at);
        $runs = 0;
        $middleware->handle(new \stdClass, function () use (&$runs): void {
            $runs++;
        });
        $middleware->handle(new \stdClass, function () use (&$runs): void {
            $runs++;
        });

        $this->assertSame(1, $runs);
        $this->assertNotNull($delivery->fresh()->sent_at);
    }

    public function test_database_delivery_recovers_when_notification_was_saved_before_acknowledgement(): void
    {
        $request = WorkRequest::factory()->create();
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $delivery = NotificationDelivery::query()->create([
            'event_key' => hash('sha256', 'database-acknowledgement'),
            'company_id' => $request->company_id,
            'work_request_id' => $request->id,
            'notification_type' => NotificationType::EstimateSubmitted->value,
            'notifiable_type' => $admin->getMorphClass(),
            'notifiable_id' => $admin->id,
            'channel' => 'database',
        ]);
        DB::table('notifications')->insert([
            'id' => $delivery->id,
            'type' => NotificationType::EstimateSubmitted->value,
            'notifiable_type' => $admin->getMorphClass(),
            'notifiable_id' => $admin->id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runs = 0;

        (new RecordNotificationDelivery($delivery->id, 'database'))->handle(
            new \stdClass,
            function () use (&$runs): void {
                $runs++;
            },
        );

        $this->assertSame(0, $runs);
        $this->assertNotNull($delivery->fresh()->sent_at);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_distinct_assignment_activities_remain_distinct_notification_events(): void
    {
        Notification::fake();
        $request = WorkRequest::factory()->create();
        $assignee = User::factory()->operator()->create();

        $request->update(['assigned_to' => $assignee->id]);
        $request->update(['assigned_to' => null]);
        $request->update(['assigned_to' => $assignee->id]);

        Notification::assertSentToTimes($assignee, BusinessNotification::class, 2);
        $this->assertSame(
            2,
            NotificationDelivery::query()
                ->where('notification_type', NotificationType::RequestAssigned->value)
                ->distinct()
                ->count('event_key'),
        );
    }

    public function test_event_scoped_notification_requires_its_source_identifier(): void
    {
        Queue::fake();
        $request = WorkRequest::factory()->create();
        User::factory()->customerAdmin()->for($request->company)->create();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('estimate_version_id');

        (new SendBusinessNotification)->handle(NotificationType::EstimateSubmitted, $request);
    }

    public function test_database_payload_uses_stable_type_and_drops_unapproved_context(): void
    {
        Notification::fake();
        $request = WorkRequest::factory()->create();
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $operator = User::factory()->operator()->create();

        (new SendBusinessNotification)->handle(
            NotificationType::EstimateSubmitted,
            $request,
            $operator,
            [
                'estimate_version_id' => 52,
                'reason' => '외부에 공개하면 안 되는 수정 사유',
                'storage_path' => 'private/secret.pdf',
            ],
        );

        Notification::assertSentTo($admin, BusinessNotification::class, function (BusinessNotification $notification): bool {
            $payload = $notification->toDatabase(new \stdClass);

            return $notification->type === NotificationType::EstimateSubmitted
                && $payload['kind'] === NotificationType::EstimateSubmitted->value
                && $payload['priority'] === 'important'
                && $payload['estimate_version_id'] === 52
                && ! array_key_exists('reason', $payload)
                && ! array_key_exists('storage_path', $payload);
        });
    }

    public function test_database_delivery_uses_enum_value_as_notification_type(): void
    {
        $request = WorkRequest::factory()->create();
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $notification = new BusinessNotification(
            NotificationType::EstimateSubmitted,
            $request->company_id,
            $request->id,
            $request->title,
            '검토할 새 견적이 제출되었습니다.',
            '/requests/'.$request->id,
            ['estimate_version_id' => 63],
        );

        Notification::sendNow($admin, $notification, ['database']);

        $row = DB::table('notifications')->sole();
        $payload = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(NotificationType::EstimateSubmitted->value, $row->type);
        $this->assertSame(NotificationType::EstimateSubmitted->value, $payload['kind']);
        $this->assertSame(63, $payload['estimate_version_id']);
        $this->assertSame('/requests/'.$request->id, $payload['action_url']);
    }

    public function test_delivery_rechecks_current_account_and_request_access(): void
    {
        $request = WorkRequest::factory()->create();
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $notification = new BusinessNotification(
            NotificationType::EstimateSubmitted,
            $request->company_id,
            $request->id,
            $request->title,
            '검토할 새 견적이 제출되었습니다.',
            '/requests/'.$request->id,
        );
        $admin->forceFill(['is_active' => false])->save();

        Notification::sendNow($admin, $notification, ['database']);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_comment_and_assignment_events_use_the_common_dispatcher(): void
    {
        Notification::fake();
        $submitter = User::factory()->customerUser()->create();
        $request = WorkRequest::factory()->for($submitter->company)->create([
            'submitted_by' => $submitter->id,
        ]);
        $assignee = User::factory()->operator()->create();
        $request->update(['assigned_to' => $assignee->id]);

        Notification::assertSentTo($assignee, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::RequestAssigned);

        $commenter = User::factory()->customerUser()->for($request->company)->create();
        $request->comments()->create([
            'company_id' => $request->company_id,
            'author_id' => $commenter->id,
            'body' => '공통 알림 발송 확인',
        ]);

        Notification::assertSentTo($submitter, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::CommentCreated);
        Notification::assertSentTo($assignee, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::CommentCreated);
        Notification::assertNotSentTo($commenter, BusinessNotification::class);
    }

    public function test_estimate_and_status_workflow_dispatches_each_business_event(): void
    {
        Notification::fake();
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();

        Notification::assertSentTo($admin, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::EstimateSubmitted);

        (new ApproveEstimateVersion)->handle(
            $admin,
            $estimate,
            (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT,
            '127.0.0.1',
            'Business notification test',
        );
        Notification::assertSentTo($operator, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::EstimateApproved);

        $workflow = new TransitionWorkRequest;
        $workflow->handle($operator, $request->fresh(), WorkRequestStatus::InProgress);
        Notification::assertSentTo($admin, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::WorkStarted);

        $workflow->handle($operator, $request->fresh(), WorkRequestStatus::AwaitingReview);
        Notification::assertSentTo($admin, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::ReviewRequested);

        $workflow->handle($admin, $request->fresh(), WorkRequestStatus::Completed);
        Notification::assertSentTo($operator, BusinessNotification::class,
            fn (BusinessNotification $notification): bool => $notification->type === NotificationType::ReviewCompleted);
    }
}
