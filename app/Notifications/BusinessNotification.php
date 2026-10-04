<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Attachment;
use App\Models\User;
use App\Models\WeeklyProgressReport;
use App\Models\WorkRequest;
use App\Queue\Middleware\RecordNotificationDelivery;
use App\Services\NotificationDeliveryFailureRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Symfony\Component\Mime\Email;
use Throwable;

class BusinessNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public const MAX_ATTEMPTS = 4;

    public const TIMEOUT_SECONDS = 30;

    public const RETRY_DELAYS_SECONDS = [60, 300, 900];

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = self::TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public ?string $queuedChannel = null;

    /**
     * @param  array<string, int|string>  $context
     * @param  array<string, string>  $deliveryIds
     * @param  list<string>|null  $channels
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly int $companyId,
        public readonly ?int $workRequestId,
        public readonly string $requestTitle,
        public readonly string $message,
        public readonly string $actionUrl,
        public readonly array $context = [],
        public readonly string $eventKey = '',
        public readonly array $deliveryIds = [],
        public readonly ?array $channels = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return self::RETRY_DELAYS_SECONDS;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if ($this->channels !== null) {
            return $this->channels;
        }

        return array_map(
            static fn ($channel): string => $channel->value,
            $this->type->channels(),
        );
    }

    /** @return list<object> */
    public function middleware(object $notifiable, string $channel): array
    {
        $this->queuedChannel = $channel;
        $deliveryId = $this->deliveryIds[$channel] ?? null;
        if ($deliveryId === null) {
            return [];
        }

        return [
            (new WithoutOverlapping('business-notification:'.$deliveryId))
                ->releaseAfter(5)
                ->expireAfter(45)
                ->shared(),
            new RecordNotificationDelivery($deliveryId, $channel),
        ];
    }

    public function failed(Throwable $exception): void
    {
        $deliveryId = $this->queuedChannel === null
            ? null
            : ($this->deliveryIds[$this->queuedChannel] ?? null);
        if ($deliveryId === null) {
            return;
        }

        (new NotificationDeliveryFailureRecorder)->record($deliveryId, $exception);
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type->value;
    }

    /** @return array<string, int|string|null> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->type->value,
            'label' => $this->type->label(),
            'priority' => $this->type->priority()->value,
            'company_id' => $this->companyId,
            'work_request_id' => $this->workRequestId,
            'request_title' => $this->requestTitle,
            'message' => $this->message,
            'action_url' => $this->actionUrl,
            ...$this->context,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $actionLabel = $this->type === NotificationType::WeeklyProgressReport
            ? '대시보드 확인'
            : '요청 확인';
        $message = (new MailMessage)
            ->subject('[APPROID Desk] '.$this->type->label())
            ->greeting($notifiable instanceof User ? $notifiable->name.'님' : '안녕하세요')
            ->line($this->message)
            ->line($this->requestTitle)
            ->action($actionLabel, url($this->actionUrl));

        $deliveryId = $this->deliveryIds['mail'] ?? null;
        if ($deliveryId !== null) {
            $message->withSymfonyMessage(static function (Email $email) use ($deliveryId): void {
                $email->getHeaders()->remove('Message-ID');
                $email->getHeaders()->addIdHeader('Message-ID', $deliveryId.'@notifications.approid-desk.local');
            });
        }

        return $message;
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        $user = User::query()->find($notifiable->id);
        if (! $user instanceof User || ! $user->canAccessWorkspace()) {
            return false;
        }

        if ($this->type === NotificationType::WeeklyProgressReport) {
            $reportId = $this->context['weekly_report_id'] ?? null;
            if (! is_int($reportId) && (! is_string($reportId) || ! ctype_digit($reportId))) {
                return false;
            }

            $report = WeeklyProgressReport::query()->find((int) $reportId);

            return $report instanceof WeeklyProgressReport
                && $report->company_id === $this->companyId
                && $report->canBeReceivedBy($user);
        }

        if ($this->workRequestId === null
            || ! WorkRequest::query()->visibleTo($user)->whereKey($this->workRequestId)->exists()) {
            return false;
        }

        $attachmentId = $this->context['attachment_id'] ?? null;

        return ! is_int($attachmentId)
            || Attachment::query()->visibleTo($user)->whereKey($attachmentId)->exists();
    }
}
