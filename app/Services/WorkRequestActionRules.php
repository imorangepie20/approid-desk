<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\WorkRequestStatus as Status;
use App\Models\User;
use App\Models\WorkRequest;

/** Manual actions only. Estimate decisions use their own atomic workflow. */
class WorkRequestActionRules
{
    public function allows(User $user, WorkRequest $request, Status $to): bool
    {
        $from = $request->status;
        $heldFrom = $from === Status::OnHold
            ? $request->statusChanges()->where('to_status', Status::OnHold->value)->latest('id')->first()?->from_status
            : null;
        if (! in_array($to, (new WorkRequestTransitionRules)->destinations($from, $heldFrom), true)) {
            return false;
        }
        // No generic approval/submission shortcut. Completed requests may only follow
        // the transition matrix's explicit free-rework path back to in-progress.
        if ($to === Status::AwaitingApproval || ($from === Status::AwaitingApproval && $to !== Status::Cancelled)) {
            return false;
        }
        if ($from === Status::AwaitingReview && in_array($to, [Status::Completed, Status::InProgress], true)) {
            if ($user->role !== UserRole::CustomerAdmin) {
                return false;
            }
        } elseif (! $user->role->isSystemRole()) {
            return false;
        }
        if (in_array($to, [Status::Queued, Status::InProgress, Status::Completed], true)) {
            $approved = $request->approvedEstimateVersion()->first();
            if ($approved === null || $request->latestEstimateVersion()->first()?->id !== $approved->id
                || ! $approved->approval()->exists()) {
                return false;
            }
            if (in_array($to, [Status::Queued, Status::InProgress], true)
                && ! ($request->serviceContract()->first()?->permitsWork() ?? false)) {
                return false;
            }
        }

        return true;
    }
}
