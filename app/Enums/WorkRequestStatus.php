<?php

namespace App\Enums;

enum WorkRequestStatus: string
{
    case Received = 'received';
    case Estimating = 'estimating';
    case AwaitingApproval = 'awaiting_approval';
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case AwaitingReview = 'awaiting_review';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => '접수',
            self::Estimating => '견적 중',
            self::AwaitingApproval => '승인 대기',
            self::Queued => '작업 대기',
            self::InProgress => '진행 중',
            self::AwaitingReview => '검수 대기',
            self::Completed => '완료',
            self::OnHold => '보류',
            self::Cancelled => '취소',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled => true,
            default => false,
        };
    }
}
