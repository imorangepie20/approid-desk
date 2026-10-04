<?php

namespace App\Actions;

use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryRetry;
use App\Models\User;
use App\Models\WeeklyProgressReport;
use App\Models\WorkRequest;
use App\Notifications\BusinessNotification;
use App\Services\NotificationDeliveryFailureRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class RetryNotificationDelivery
{
    public function handle(User $actor, NotificationDelivery $delivery): NotificationDelivery
    {
        [$delivery, $recipient, $notification] = DB::transaction(function () use ($actor, $delivery): array {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize(Permission::ManageNotifications->value);
            $delivery = NotificationDelivery::query()->lockForUpdate()->findOrFail($delivery->id);

            if ($delivery->status !== NotificationDeliveryStatus::Failed || $delivery->sent_at !== null) {
                throw ValidationException::withMessages(['delivery' => '최종 실패 상태의 알림만 재시도할 수 있습니다.']);
            }

            $recipient = User::query()->find($delivery->notifiable_id);
            if ($delivery->notifiable_type !== (new User)->getMorphClass()
                || ! $recipient instanceof User
                || ! $recipient->canAccessWorkspace()
                || ! $this->recipientCanReceive($delivery, $recipient)) {
                throw ValidationException::withMessages(['delivery' => '현재 알림을 받을 수 있는 사용자가 아닙니다.']);
            }

            $notification = $this->notification($delivery);
            $retry = new NotificationDeliveryRetry;
            $retry->forceFill([
                'notification_delivery_id' => $delivery->id,
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ])->save();
            $delivery->forceFill(['status' => NotificationDeliveryStatus::Pending])->save();

            return [$delivery, $recipient, $notification];
        });

        try {
            Notification::send($recipient, $notification);
        } catch (Throwable $exception) {
            (new NotificationDeliveryFailureRecorder)->record($delivery->id, $exception);

            throw $exception;
        }

        return $delivery->fresh() ?? $delivery;
    }

    private function notification(NotificationDelivery $delivery): BusinessNotification
    {
        $data = $delivery->delivery_data;
        if (! is_array($data)) {
            throw ValidationException::withMessages(['delivery' => '재시도에 필요한 알림 정보가 없습니다.']);
        }

        $requestTitle = $data['request_title'] ?? null;
        $message = $data['message'] ?? null;
        $actionUrl = $data['action_url'] ?? null;
        $context = $data['context'] ?? null;
        if (! is_string($requestTitle) || $requestTitle === ''
            || ! is_string($message) || $message === ''
            || ! is_string($actionUrl) || ! $this->validActionUrl($delivery, $actionUrl)
            || ! is_array($context)
            || array_filter($context, static fn (mixed $value): bool => ! is_int($value) && ! is_string($value)) !== []) {
            throw ValidationException::withMessages(['delivery' => '재시도에 필요한 알림 정보가 올바르지 않습니다.']);
        }

        /** @var array<string, int|string> $context */
        $notification = new BusinessNotification(
            type: $delivery->notification_type,
            companyId: $delivery->company_id,
            workRequestId: $delivery->work_request_id,
            requestTitle: $requestTitle,
            message: $message,
            actionUrl: $actionUrl,
            context: $context,
            eventKey: $delivery->event_key,
            deliveryIds: [$delivery->channel->value => $delivery->id],
            channels: [$delivery->channel->value],
        );
        $notification->id = $delivery->id;

        return $notification;
    }

    private function recipientCanReceive(NotificationDelivery $delivery, User $recipient): bool
    {
        if ($delivery->notification_type === NotificationType::WeeklyProgressReport) {
            $report = WeeklyProgressReport::query()->find($delivery->weekly_progress_report_id);

            return $report instanceof WeeklyProgressReport
                && $report->company_id === $delivery->company_id
                && $report->canBeReceivedBy($recipient);
        }

        return $delivery->work_request_id !== null
            && WorkRequest::query()->visibleTo($recipient)->whereKey($delivery->work_request_id)->exists();
    }

    private function validActionUrl(NotificationDelivery $delivery, string $actionUrl): bool
    {
        if ($delivery->notification_type === NotificationType::WeeklyProgressReport) {
            return $actionUrl === '/dashboard';
        }

        return str_starts_with($actionUrl, '/requests/');
    }
}
