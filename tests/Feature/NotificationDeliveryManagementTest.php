<?php

namespace Tests\Feature;

use App\Actions\RetryNotificationDelivery;
use App\Actions\SendBusinessNotification;
use App\Enums\NotificationDeliveryFailure;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryRetry;
use App\Models\User;
use App\Models\WorkRequest;
use App\Notifications\BusinessNotification;
use App\Queue\Middleware\RecordNotificationDelivery;
use App\Services\NotificationDeliveryFailureRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class NotificationDeliveryManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{\Throwable, NotificationDeliveryFailure}> */
    public static function deliveryFailures(): array
    {
        return [
            'timeout' => [new TimeoutExceededException('secret timeout detail'), NotificationDeliveryFailure::Timeout],
            'attempts exhausted' => [new MaxAttemptsExceededException('secret attempts detail'), NotificationDeliveryFailure::AttemptsExhausted],
            'mail transport' => [new TransportException('secret SMTP detail'), NotificationDeliveryFailure::MailTransport],
            'database' => [new QueryException('mysql', 'select ?', ['secret'], new RuntimeException('secret database detail')), NotificationDeliveryFailure::Database],
            'unexpected' => [new RuntimeException('secret unexpected detail'), NotificationDeliveryFailure::Unexpected],
        ];
    }

    #[DataProvider('deliveryFailures')]
    public function test_terminal_failures_are_classified_without_storing_exception_details(
        \Throwable $exception,
        NotificationDeliveryFailure $expected,
    ): void {
        $request = WorkRequest::factory()->create();
        $recipient = User::factory()->customerAdmin()->for($request->company)->create();
        $delivery = NotificationDelivery::query()->create([
            'event_key' => hash('sha256', 'classified-failure-'.$expected->value),
            'company_id' => $request->company_id,
            'work_request_id' => $request->id,
            'notification_type' => NotificationType::EstimateSubmitted,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id,
            'channel' => 'mail',
        ]);

        (new NotificationDeliveryFailureRecorder)->record($delivery->id, $exception);

        $delivery = $delivery->fresh();
        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame($expected, $delivery->failure_code);
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->sent_at);
        $this->assertStringNotContainsString('secret', json_encode($delivery->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_final_failure_records_safe_reason_and_attempt_metadata(): void
    {
        [, , $delivery, $job] = $this->failedMailDelivery('smtp password=do-not-store');

        $delivery = $delivery->fresh();
        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(NotificationDeliveryFailure::MailTransport, $delivery->failure_code);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNotNull($delivery->last_attempted_at);
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->sent_at);
        $this->assertInstanceOf(BusinessNotification::class, $job->notification);
        $this->assertSame('mail', $job->notification->queuedChannel);
        $this->assertStringNotContainsString('password', json_encode($delivery->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_only_system_roles_can_view_failed_deliveries_and_retry_controls(): void
    {
        [$operator, $recipient, $delivery] = $this->failedMailDelivery();

        $this->actingAs($operator)->get(route('notification-deliveries.index'))
            ->assertOk()
            ->assertSee('알림 발송')
            ->assertSee($delivery->notification_type->label())
            ->assertSee($delivery->workRequest->title)
            ->assertSee($recipient->email)
            ->assertSee('메일 전송 오류')
            ->assertSee('재시도');
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->get(route('notification-deliveries.index'))->assertOk();

        foreach ([
            User::factory()->customerAdmin()->create(),
            User::factory()->customerUser()->create(),
        ] as $customer) {
            $this->actingAs($customer)->get(route('notification-deliveries.index'))->assertForbidden();
            $this->post(route('notification-deliveries.retry', $delivery))->assertForbidden();
        }

        $operator->forceFill(['is_active' => false])->save();
        $this->actingAs($operator)->get(route('notification-deliveries.index'))->assertForbidden();
    }

    public function test_operator_can_queue_one_audited_retry_for_a_failed_delivery(): void
    {
        [$operator, , $delivery] = $this->failedMailDelivery();
        Queue::fake();

        $this->actingAs($operator)
            ->post(route('notification-deliveries.retry', $delivery))
            ->assertRedirect(route('notification-deliveries.index', ['status' => 'failed']))
            ->assertSessionHas('success');

        $delivery = $delivery->fresh();
        $this->assertSame(NotificationDeliveryStatus::Pending, $delivery->status);
        $retry = NotificationDeliveryRetry::query()->sole();
        $this->assertSame($delivery->id, $retry->notification_delivery_id);
        $this->assertSame($operator->id, $retry->requested_by);
        Queue::assertPushed(SendQueuedNotifications::class, 1);
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($delivery): bool {
            return $job->channels === ['mail']
                && $job->notification instanceof BusinessNotification
                && $job->notification->id === $delivery->id
                && $job->notification->deliveryIds === ['mail' => $delivery->id];
        });

        $this->post(route('notification-deliveries.retry', $delivery))
            ->assertSessionHasErrors('delivery');
        $this->assertDatabaseCount('notification_delivery_retries', 1);
        Queue::assertPushed(SendQueuedNotifications::class, 1);
    }

    public function test_successful_manual_retry_marks_the_same_delivery_sent(): void
    {
        [$operator, , $delivery] = $this->failedMailDelivery();
        Queue::fake();

        (new RetryNotificationDelivery)->handle($operator, $delivery);
        $job = null;
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertInstanceOf(SendQueuedNotifications::class, $job);
        $middleware = collect($job->middleware)->first(
            fn (object $item): bool => $item instanceof RecordNotificationDelivery,
        );
        $this->assertInstanceOf(RecordNotificationDelivery::class, $middleware);
        $middleware->handle($job, static function (): void {});

        $delivery = $delivery->fresh();
        $this->assertSame(NotificationDeliveryStatus::Sent, $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame(2, $delivery->attempt_count);
        $this->assertSame(NotificationDeliveryFailure::MailTransport, $delivery->failure_code);
        $this->assertDatabaseCount('notification_delivery_retries', 1);
    }

    public function test_retry_rejects_missing_snapshot_inactive_recipient_and_non_failed_delivery(): void
    {
        [$operator, $recipient, $delivery] = $this->failedMailDelivery();
        Queue::fake();
        $delivery->forceFill(['delivery_data' => null])->save();
        $this->actingAs($operator)->post(route('notification-deliveries.retry', $delivery))
            ->assertSessionHasErrors('delivery');
        $this->assertSame(NotificationDeliveryStatus::Failed, $delivery->fresh()->status);

        $delivery->forceFill(['delivery_data' => $this->deliveryData($delivery->workRequest)])->save();
        $recipient->forceFill(['is_active' => false])->save();
        $this->post(route('notification-deliveries.retry', $delivery))->assertSessionHasErrors('delivery');

        $recipient->forceFill(['is_active' => true])->save();
        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Sent,
            'sent_at' => now(),
        ])->save();
        $this->post(route('notification-deliveries.retry', $delivery))->assertSessionHasErrors('delivery');
        $this->assertDatabaseCount('notification_delivery_retries', 0);
        Queue::assertNothingPushed();
    }

    public function test_retry_history_is_immutable_in_models_and_database(): void
    {
        [$operator, , $delivery] = $this->failedMailDelivery();
        Queue::fake();
        (new RetryNotificationDelivery)->handle($operator, $delivery);
        $retry = NotificationDeliveryRetry::query()->sole();

        try {
            $retry->forceFill(['requested_at' => now()->addMinute()])->save();
            $this->fail('Retry history model updates must be blocked.');
        } catch (\LogicException $exception) {
            $this->assertSame('감사 기록은 수정할 수 없습니다.', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('notification_delivery_retries')->where('id', $retry->id)->delete();
    }

    /**
     * @return array{User, User, NotificationDelivery, SendQueuedNotifications}
     */
    private function failedMailDelivery(string $message = 'smtp unavailable'): array
    {
        Queue::fake();
        $request = WorkRequest::factory()->create();
        $recipient = User::factory()->customerAdmin()->for($request->company)->create();
        $operator = User::factory()->operator()->create();
        (new SendBusinessNotification)->handle(
            NotificationType::EstimateSubmitted,
            $request,
            $operator,
            ['estimate_version_id' => 913],
        );
        $delivery = NotificationDelivery::query()->where('channel', 'mail')->sole();
        $job = null;
        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $queued) use (&$job): bool {
            if ($queued->channels !== ['mail']) {
                return false;
            }

            $job = $queued;

            return true;
        });
        $this->assertInstanceOf(SendQueuedNotifications::class, $job);
        $middleware = collect($job->middleware)->first(
            fn (object $item): bool => $item instanceof RecordNotificationDelivery,
        );
        $this->assertInstanceOf(RecordNotificationDelivery::class, $middleware);
        $exception = new TransportException($message);

        try {
            $middleware->handle($job, static fn () => throw $exception);
            $this->fail('The delivery exception must reach the queue worker.');
        } catch (TransportException $caught) {
            $this->assertSame($exception, $caught);
        }
        $this->assertInstanceOf(BusinessNotification::class, $job->notification);
        $job->notification->failed($exception);

        return [$operator, $recipient, $delivery->fresh(), $job];
    }

    /** @return array<string, mixed> */
    private function deliveryData(WorkRequest $request): array
    {
        return [
            'request_title' => $request->title,
            'message' => '검토할 새 견적이 제출되었습니다.',
            'action_url' => '/requests/'.$request->id,
            'context' => ['estimate_version_id' => 913],
        ];
    }
}
