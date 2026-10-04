<?php

namespace App\Enums;

enum NotificationAudience: string
{
    case Operations = 'operations';
    case CompanyAdmins = 'company_admins';
    case RequestSubmitter = 'request_submitter';
    case RequestAssignee = 'request_assignee';
    case RequestStakeholders = 'request_stakeholders';
    case AttachmentUploader = 'attachment_uploader';

    public function label(): string
    {
        return match ($this) {
            self::Operations => '운영 계정',
            self::CompanyAdmins => '고객사 관리자',
            self::RequestSubmitter => '요청 등록자',
            self::RequestAssignee => '요청 담당자',
            self::RequestStakeholders => '요청 참여자',
            self::AttachmentUploader => '첨부 업로더',
        };
    }
}
