<?php

namespace App\Enums;

enum WorkRequestActivityType: string
{
    case RequestCreated = 'request_created';
    case RequestUpdated = 'request_updated';
    case AssigneeChanged = 'assignee_changed';
    case CommentCreated = 'comment_created';
    case StatusChanged = 'status_changed';

    public function label(): string
    {
        return match ($this) {
            self::RequestCreated => '요청 등록',
            self::RequestUpdated => '요청 수정',
            self::AssigneeChanged => '담당자 변경',
            self::CommentCreated => '댓글 등록',
            self::StatusChanged => '상태 변경',
        };
    }
}
