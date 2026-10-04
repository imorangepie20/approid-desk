<?php

namespace App\Actions;

use App\Enums\NotificationType;
use App\Models\Attachment;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\WorkRequest;
use App\Notifications\BusinessNotification;
use App\Services\NotificationRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;

class SendBusinessNotification
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(
        NotificationType $type,
        WorkRequest $request,
        ?User $actor = null,
        array $context = [],
        ?Attachment $attachment = null,
    ): int {
        if (! $type->isWorkRequestScoped()) {
            throw new LogicException('요청 범위가 아닌 알림은 별도 보고서 발송기를 사용해야 합니다.');
        }

        $request = WorkRequest::query()->findOrFail($request->id);
        $context = $this->safeContext($type, $context);
        $eventKey = $this->eventKey($type, $request, $context);
        $recipients = (new NotificationRecipients)->for($type, $request, $actor, $attachment);

        if ($recipients->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($type, $request, $context, $eventKey, $recipients): int {
            $scheduledRecipients = 0;
            $message = $this->message($type);
            $actionUrl = '/requests/'.$request->id;

            foreach ($recipients as $recipient) {
                $deliveryIds = [];
                foreach ($type->channels() as $channel) {
                    $delivery = NotificationDelivery::query()->createOrFirst(
                        [
                            'event_key' => $eventKey,
                            'notifiable_type' => $recipient->getMorphClass(),
                            'notifiable_id' => $recipient->getKey(),
                            'channel' => $channel->value,
                        ],
                        [
                            'company_id' => $request->company_id,
                            'work_request_id' => $request->id,
                            'notification_type' => $type->value,
                            'delivery_data' => [
                                'request_title' => $request->title,
                                'message' => $message,
                                'action_url' => $actionUrl,
                                'context' => $context,
                            ],
                        ],
                    );

                    if ($delivery->wasRecentlyCreated) {
                        $deliveryIds[$channel->value] = $delivery->id;
                    }
                }

                if ($deliveryIds === []) {
                    continue;
                }

                $notification = new BusinessNotification(
                    type: $type,
                    companyId: $request->company_id,
                    workRequestId: $request->id,
                    requestTitle: $request->title,
                    message: $message,
                    actionUrl: $actionUrl,
                    context: $context,
                    eventKey: $eventKey,
                    deliveryIds: $deliveryIds,
                    channels: array_keys($deliveryIds),
                );
                $notification->id = $deliveryIds['database'] ?? array_values($deliveryIds)[0];
                Notification::send($recipient, $notification);
                $scheduledRecipients++;
            }

            return $scheduledRecipients;
        });
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, int|string>
     */
    private function safeContext(NotificationType $type, array $context): array
    {
        $allowed = match ($type) {
            NotificationType::RequestAssigned => ['activity_id'],
            NotificationType::MajorIncidentUpdated => ['major_incident_event_id'],
            NotificationType::MajorIncidentRollbackUpdated => ['major_incident_rollback_id', 'rollback_phase'],
            NotificationType::CommentCreated => ['comment_id'],
            NotificationType::EstimateSubmitted,
            NotificationType::EstimateApproved,
            NotificationType::EstimateRevisionRequested => ['estimate_version_id'],
            NotificationType::WorkStarted,
            NotificationType::WorkPaused,
            NotificationType::WorkResumed,
            NotificationType::ReviewRequested,
            NotificationType::ReviewCompleted,
            NotificationType::RequestCancelled => ['status_change_id'],
            NotificationType::MonthTransitionNeeded => [
                'notice_id',
                'contract_month_id',
                'month',
                'remaining_minutes',
            ],
            NotificationType::AttachmentInfected,
            NotificationType::AttachmentScanFailed => ['attachment_id', 'scan_attempt_id'],
            default => [],
        };

        $safe = [];
        foreach ($allowed as $key) {
            $value = $context[$key] ?? null;
            if (is_int($value) || is_string($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @param array<string, int|string> $context */
    private function eventKey(NotificationType $type, WorkRequest $request, array $context): string
    {
        $source = match ($type) {
            NotificationType::RequestCreated,
            NotificationType::UrgentRequestCreated => 'work_request:'.$request->id,
            NotificationType::MajorIncidentUpdated => 'major_incident_event:'.$this->requiredContext($context, 'major_incident_event_id'),
            NotificationType::MajorIncidentRollbackUpdated => $this->rollbackEventSource($context),
            NotificationType::RequestAssigned => 'activity:'.$this->requiredContext($context, 'activity_id'),
            NotificationType::CommentCreated => 'comment:'.$this->requiredContext($context, 'comment_id'),
            NotificationType::EstimateSubmitted,
            NotificationType::EstimateApproved,
            NotificationType::EstimateRevisionRequested => 'estimate_version:'.$this->requiredContext($context, 'estimate_version_id'),
            NotificationType::WorkStarted,
            NotificationType::WorkPaused,
            NotificationType::WorkResumed,
            NotificationType::ReviewRequested,
            NotificationType::ReviewCompleted,
            NotificationType::RequestCancelled => 'status_change:'.$this->requiredContext($context, 'status_change_id'),
            NotificationType::MonthTransitionNeeded => 'notice:'.$this->requiredContext($context, 'notice_id'),
            NotificationType::AttachmentInfected,
            NotificationType::AttachmentScanFailed => 'scan_attempt:'.$this->requiredContext($context, 'scan_attempt_id'),
            NotificationType::WeeklyProgressReport => throw new LogicException('주간 보고 알림은 이 발송기에서 처리하지 않습니다.'),
        };

        return hash('sha256', $type->value.'|'.$request->id.'|'.$source);
    }

    /** @param array<string, int|string> $context */
    private function requiredContext(array $context, string $key): int|string
    {
        $value = $context[$key] ?? null;
        if (! is_int($value) && (! is_string($value) || trim($value) === '')) {
            throw new LogicException('알림 사건 식별자 '.$key.'가 필요합니다.');
        }

        return $value;
    }

    /** @param array<string, int|string> $context */
    private function rollbackEventSource(array $context): string
    {
        $rollbackId = $this->requiredContext($context, 'major_incident_rollback_id');
        $phase = $this->requiredContext($context, 'rollback_phase');
        if (! in_array($phase, ['started', 'completed'], true)) {
            throw new LogicException('알림 롤백 단계가 올바르지 않습니다.');
        }

        return 'major_incident_rollback:'.$rollbackId.':'.$phase;
    }

    private function message(NotificationType $type): string
    {
        return match ($type) {
            NotificationType::RequestCreated => '새 요청이 등록되었습니다.',
            NotificationType::UrgentRequestCreated => '긴급 요청이 등록되었습니다.',
            NotificationType::MajorIncidentUpdated => '주요 업무 장애 대응 이력이 등록되었습니다.',
            NotificationType::MajorIncidentRollbackUpdated => '주요 업무 장애 롤백 기록이 갱신되었습니다.',
            NotificationType::RequestAssigned => '요청 담당자로 지정되었습니다.',
            NotificationType::CommentCreated => '요청에 새 댓글이 등록되었습니다.',
            NotificationType::EstimateSubmitted => '검토할 새 견적이 제출되었습니다.',
            NotificationType::EstimateApproved => '고객사가 견적을 승인했습니다.',
            NotificationType::EstimateRevisionRequested => '고객사가 견적 수정을 요청했습니다.',
            NotificationType::WorkStarted => '요청 작업이 시작되었습니다.',
            NotificationType::WorkPaused => '요청 작업이 보류되었습니다.',
            NotificationType::WorkResumed => '요청 작업이 재개되었습니다.',
            NotificationType::ReviewRequested => '작업 검수를 요청했습니다.',
            NotificationType::ReviewCompleted => '고객 검수가 완료되었습니다.',
            NotificationType::RequestCancelled => '요청이 취소되었습니다.',
            NotificationType::MonthTransitionNeeded => '월 전환 대상의 남은 예약을 확인하고 계속 작업은 다음 달 견적으로 승인해 주세요.',
            NotificationType::AttachmentInfected => '첨부파일에서 악성 콘텐츠가 감지되어 격리되었습니다.',
            NotificationType::AttachmentScanFailed => '첨부파일 안전 검사가 완료되지 않아 다운로드가 차단되었습니다.',
            NotificationType::WeeklyProgressReport => throw new LogicException('주간 보고 알림은 이 발송기에서 처리하지 않습니다.'),
        };
    }
}
