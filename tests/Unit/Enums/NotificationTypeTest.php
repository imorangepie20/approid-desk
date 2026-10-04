<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationAudience;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use PHPUnit\Framework\TestCase;

class NotificationTypeTest extends TestCase
{
    public function test_supporting_contract_values_and_labels_are_stable(): void
    {
        $this->assertSame(
            ['operations', 'company_admins', 'request_submitter', 'request_assignee', 'request_stakeholders', 'attachment_uploader'],
            array_column(NotificationAudience::cases(), 'value'),
        );
        $this->assertSame('운영 계정', NotificationAudience::Operations->label());
        $this->assertSame('고객사 관리자', NotificationAudience::CompanyAdmins->label());
        $this->assertSame('요청 등록자', NotificationAudience::RequestSubmitter->label());
        $this->assertSame('요청 담당자', NotificationAudience::RequestAssignee->label());
        $this->assertSame('요청 참여자', NotificationAudience::RequestStakeholders->label());
        $this->assertSame('첨부 업로더', NotificationAudience::AttachmentUploader->label());

        $this->assertSame(['database', 'mail'], array_column(NotificationChannel::cases(), 'value'));
        $this->assertSame(['normal', 'important', 'urgent'], array_column(NotificationPriority::cases(), 'value'));
        $this->assertSame('일반', NotificationPriority::Normal->label());
        $this->assertSame('중요', NotificationPriority::Important->label());
        $this->assertSame('긴급', NotificationPriority::Urgent->label());
    }

    public function test_types_have_stable_unique_values_and_labels(): void
    {
        $expected = [
            'request_created',
            'urgent_request_created',
            'major_incident_updated',
            'major_incident_rollback_updated',
            'request_assigned',
            'comment_created',
            'estimate_submitted',
            'estimate_approved',
            'estimate_revision_requested',
            'work_started',
            'work_paused',
            'work_resumed',
            'review_requested',
            'review_completed',
            'request_cancelled',
            'month_transition_needed',
            'attachment_infected',
            'attachment_scan_failed',
            'weekly_progress_report',
        ];

        $this->assertSame($expected, array_column(NotificationType::cases(), 'value'));
        $this->assertCount(count($expected), array_unique($expected));
        foreach (NotificationType::cases() as $type) {
            $this->assertNotSame('', $type->label());
        }
    }

    public function test_audience_groups_are_explicit_for_each_type(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->assertNotSame([], $type->audiences());
            $this->assertContainsOnlyInstancesOf(NotificationAudience::class, $type->audiences());
        }

        $this->assertSame([NotificationAudience::Operations], NotificationType::UrgentRequestCreated->audiences());
        $this->assertSame(
            [NotificationAudience::CompanyAdmins, NotificationAudience::RequestSubmitter],
            NotificationType::MajorIncidentUpdated->audiences(),
        );
        $this->assertSame(
            [NotificationAudience::CompanyAdmins, NotificationAudience::RequestSubmitter],
            NotificationType::MajorIncidentRollbackUpdated->audiences(),
        );
        $this->assertSame([NotificationAudience::CompanyAdmins], NotificationType::EstimateSubmitted->audiences());
        $this->assertSame(
            [NotificationAudience::Operations, NotificationAudience::AttachmentUploader],
            NotificationType::AttachmentInfected->audiences(),
        );
    }

    public function test_all_types_use_database_and_attention_types_also_use_mail(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->assertContains(NotificationChannel::Database, $type->channels());
        }

        $this->assertSame([NotificationChannel::Database], NotificationType::CommentCreated->channels());
        $this->assertSame(
            [NotificationChannel::Database, NotificationChannel::Mail],
            NotificationType::EstimateSubmitted->channels(),
        );
        $this->assertContains(NotificationChannel::Mail, NotificationType::WeeklyProgressReport->channels());

        $mailTypes = array_values(array_map(
            fn (NotificationType $type): string => $type->value,
            array_filter(
                NotificationType::cases(),
                fn (NotificationType $type): bool => in_array(NotificationChannel::Mail, $type->channels(), true),
            ),
        ));
        $this->assertSame([
            'urgent_request_created',
            'major_incident_updated',
            'major_incident_rollback_updated',
            'estimate_submitted',
            'estimate_approved',
            'estimate_revision_requested',
            'work_paused',
            'review_requested',
            'review_completed',
            'request_cancelled',
            'month_transition_needed',
            'attachment_infected',
            'attachment_scan_failed',
            'weekly_progress_report',
        ], $mailTypes);
    }

    public function test_priorities_separate_routine_action_and_security_events(): void
    {
        $this->assertSame(NotificationPriority::Normal, NotificationType::RequestCreated->priority());
        $this->assertSame(NotificationPriority::Important, NotificationType::ReviewRequested->priority());
        $this->assertSame(NotificationPriority::Urgent, NotificationType::AttachmentInfected->priority());
        $this->assertSame(NotificationPriority::Urgent, NotificationType::AttachmentScanFailed->priority());
    }

    public function test_only_the_weekly_report_is_not_scoped_to_one_request(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->assertSame($type !== NotificationType::WeeklyProgressReport, $type->isWorkRequestScoped());
        }
    }
}
