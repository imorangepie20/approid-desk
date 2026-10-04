<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WorkRequestStatus;
use App\Models\User;
use App\Models\WorkRequest;
use App\Policies\Concerns\ChecksCompanyScope;
use App\Services\WorkRequestActionRules;

class WorkRequestPolicy
{
    use ChecksCompanyScope;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCompanyListings($user);
    }

    public function view(User $user, WorkRequest $workRequest): bool
    {
        return $this->canAccessCompany($user, $workRequest->company_id);
    }

    public function create(User $user): bool
    {
        return $user->is_active
            && $user->role->hasPermission(Permission::CreateRequests);
    }

    public function update(User $user, WorkRequest $workRequest): bool
    {
        return $this->hasCompanyPermission(
            $user,
            Permission::ManageCompanyRequests,
            $workRequest->company_id,
        );
    }

    public function delete(User $user, WorkRequest $workRequest): bool
    {
        return $this->update($user, $workRequest);
    }

    public function transition(User $user, WorkRequest $workRequest, WorkRequestStatus $to): bool
    {
        return $this->view($user, $workRequest) && (new WorkRequestActionRules)->allows($user, $workRequest, $to);
    }
}
