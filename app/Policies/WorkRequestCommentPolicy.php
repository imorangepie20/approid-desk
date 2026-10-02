<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use App\Policies\Concerns\ChecksCompanyScope;

class WorkRequestCommentPolicy
{
    use ChecksCompanyScope;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCompanyListings($user);
    }

    public function view(User $user, WorkRequestComment $workRequestComment): bool
    {
        return $this->canAccessCompany($user, $workRequestComment->company_id);
    }

    public function create(User $user, WorkRequest $workRequest): bool
    {
        return $this->hasCompanyPermission(
            $user,
            Permission::CommentOnRequests,
            $workRequest->company_id,
        );
    }
}
