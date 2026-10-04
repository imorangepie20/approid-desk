<?php

namespace App\Enums;

enum NotificationType: string
{
    case RequestCreated = 'request_created';
    case UrgentRequestCreated = 'urgent_request_created';
    case MajorIncidentUpdated = 'major_incident_updated';
    case MajorIncidentRollbackUpdated = 'major_incident_rollback_updated';
    case RequestAssigned = 'request_assigned';
    case CommentCreated = 'comment_created';
    case EstimateSubmitted = 'estimate_submitted';
    case EstimateApproved = 'estimate_approved';
    case EstimateRevisionRequested = 'estimate_revision_requested';
    case WorkStarted = 'work_started';
    case WorkPaused = 'work_paused';
    case WorkResumed = 'work_resumed';
    case ReviewRequested = 'review_requested';
    case ReviewCompleted = 'review_completed';
    case RequestCancelled = 'request_cancelled';
    case MonthTransitionNeeded = 'month_transition_needed';
    case AttachmentInfected = 'attachment_infected';
    case AttachmentScanFailed = 'attachment_scan_failed';
    case WeeklyProgressReport = 'weekly_progress_report';

    public function label(): string
    {
        return match ($this) {
            self::RequestCreated => '새 요청 등록',
            self::UrgentRequestCreated => '긴급 요청 등록',
            self::MajorIncidentUpdated => '주요 장애 대응 기록',
            self::MajorIncidentRollbackUpdated => '주요 장애 롤백 기록',
            self::RequestAssigned => '요청 담당자 지정',
            self::CommentCreated => '요청 댓글 등록',
            self::EstimateSubmitted => '견적 제출',
            self::EstimateApproved => '견적 승인',
            self::EstimateRevisionRequested => '견적 수정 요청',
            self::WorkStarted => '작업 시작',
            self::WorkPaused => '작업 보류',
            self::WorkResumed => '작업 재개',
            self::ReviewRequested => '검수 요청',
            self::ReviewCompleted => '검수 완료',
            self::RequestCancelled => '요청 취소',
            self::MonthTransitionNeeded => '월 전환 확인',
            self::AttachmentInfected => '감염 첨부 발견',
            self::AttachmentScanFailed => '첨부 검사 실패',
            self::WeeklyProgressReport => '주간 진행 보고',
        };
    }

    public function priority(): NotificationPriority
    {
        return match ($this) {
            self::UrgentRequestCreated,
            self::AttachmentInfected,
            self::AttachmentScanFailed => NotificationPriority::Urgent,
            self::EstimateSubmitted,
            self::MajorIncidentUpdated,
            self::MajorIncidentRollbackUpdated,
            self::EstimateApproved,
            self::EstimateRevisionRequested,
            self::WorkPaused,
            self::ReviewRequested,
            self::ReviewCompleted,
            self::RequestCancelled,
            self::MonthTransitionNeeded => NotificationPriority::Important,
            default => NotificationPriority::Normal,
        };
    }

    /** @return list<NotificationAudience> */
    public function audiences(): array
    {
        return match ($this) {
            self::RequestCreated,
            self::UrgentRequestCreated => [NotificationAudience::Operations],
            self::MajorIncidentUpdated,
            self::MajorIncidentRollbackUpdated => [NotificationAudience::CompanyAdmins, NotificationAudience::RequestSubmitter],
            self::RequestAssigned => [NotificationAudience::RequestAssignee],
            self::CommentCreated => [NotificationAudience::RequestStakeholders],
            self::EstimateSubmitted,
            self::ReviewRequested => [NotificationAudience::CompanyAdmins],
            self::EstimateApproved,
            self::EstimateRevisionRequested,
            self::ReviewCompleted => [NotificationAudience::Operations, NotificationAudience::RequestAssignee],
            self::WorkStarted,
            self::WorkPaused,
            self::WorkResumed => [NotificationAudience::CompanyAdmins, NotificationAudience::RequestSubmitter],
            self::RequestCancelled => [NotificationAudience::Operations, NotificationAudience::CompanyAdmins, NotificationAudience::RequestSubmitter],
            self::MonthTransitionNeeded,
            self::WeeklyProgressReport => [NotificationAudience::Operations, NotificationAudience::CompanyAdmins],
            self::AttachmentInfected,
            self::AttachmentScanFailed => [NotificationAudience::Operations, NotificationAudience::AttachmentUploader],
        };
    }

    /** @return list<NotificationChannel> */
    public function channels(): array
    {
        $channels = [NotificationChannel::Database];
        if ($this->priority() !== NotificationPriority::Normal || $this === self::WeeklyProgressReport) {
            $channels[] = NotificationChannel::Mail;
        }

        return $channels;
    }

    public function isWorkRequestScoped(): bool
    {
        return $this !== self::WeeklyProgressReport;
    }
}
