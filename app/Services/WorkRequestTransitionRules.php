<?php

namespace App\Services;

use App\Enums\WorkRequestStatus as Status;

class WorkRequestTransitionRules
{
    /** @return list<Status> */
    public function destinations(Status $from, ?Status $heldFrom = null): array
    {
        return match ($from) {
            Status::Received => [Status::Estimating, Status::OnHold, Status::Cancelled],
            Status::Estimating => [Status::AwaitingApproval, Status::OnHold, Status::Cancelled],
            Status::AwaitingApproval => [Status::Queued, Status::Estimating, Status::Cancelled],
            Status::Queued => [Status::InProgress, Status::AwaitingApproval, Status::OnHold, Status::Cancelled],
            Status::InProgress => [Status::AwaitingReview, Status::AwaitingApproval, Status::Queued, Status::OnHold, Status::Cancelled],
            Status::AwaitingReview => [Status::Completed, Status::InProgress, Status::OnHold],
            Status::Completed => [Status::InProgress],
            Status::OnHold => in_array($heldFrom, [
                Status::Received,
                Status::Estimating,
                Status::Queued,
                Status::InProgress,
                Status::AwaitingReview,
            ], true) ? [$heldFrom, Status::Cancelled] : [Status::Cancelled],
            Status::Cancelled => [],
        };
    }

    public function requiresReason(Status $from, Status $to): bool
    {
        return in_array($to, [Status::OnHold, Status::Cancelled], true)
            || in_array($from, [Status::OnHold, Status::Completed], true)
            || ($from === Status::AwaitingApproval && $to === Status::Estimating)
            || ($from === Status::AwaitingReview && $to === Status::InProgress)
            || ($from === Status::InProgress && $to === Status::Queued);
    }
}
