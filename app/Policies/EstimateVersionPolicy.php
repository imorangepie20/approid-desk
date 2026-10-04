<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use App\Policies\Concerns\ChecksCompanyScope;
use App\Services\EstimateWorkflowRules;

class EstimateVersionPolicy
{
    use ChecksCompanyScope;

    public function create(User $user, WorkRequest $request): bool
    {
        return $this->hasCompanyPermission($user, Permission::CreateEstimates, $request->company_id);
    }

    public function view(User $user, EstimateVersion $estimate): bool
    {
        return $this->canAccessCompany($user, $estimate->company_id)
            && ($user->role->isSystemRole() || $estimate->submitted_at !== null);
    }

    public function submit(User $user, EstimateVersion $estimate): bool
    {
        return $this->hasCompanyPermission($user, Permission::CreateEstimates, $estimate->company_id);
    }

    public function approve(User $user, EstimateVersion $estimate): bool
    {
        return $user->role === UserRole::CustomerAdmin
            && $this->canAccessCompany($user, $estimate->company_id);
    }

    public function requestRevision(User $user, EstimateVersion $estimate): bool
    {
        return $this->approve($user, $estimate);
    }

    public function writeDraft(User $user, WorkRequest $request): bool
    {
        return $this->create($user, $request) && (new EstimateWorkflowRules)->canWrite($request);
    }

    public function submitDraft(User $user, EstimateVersion $estimate): bool
    {
        return $this->submit($user, $estimate)
            && (new EstimateWorkflowRules)->canSubmit($estimate->workRequest, $estimate);
    }

    public function decide(User $user, EstimateVersion $estimate): bool
    {
        return $this->approve($user, $estimate)
            && (new EstimateWorkflowRules)->canDecide($estimate->workRequest, $estimate);
    }
}
