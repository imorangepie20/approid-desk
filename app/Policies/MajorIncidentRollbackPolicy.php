<?php

namespace App\Policies;

use App\Models\MajorIncidentRollback;
use App\Models\User;
use App\Models\WorkRequest;
use App\Policies\Concerns\ChecksCompanyScope;

class MajorIncidentRollbackPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, MajorIncidentRollback $rollback): bool
    {
        return $this->canAccessCompany($user, $rollback->company_id);
    }

    public function create(User $user, WorkRequest $workRequest): bool
    {
        return $user->is_active
            && $user->role->isSystemRole()
            && $workRequest->isMajorIncident();
    }

    public function complete(User $user, MajorIncidentRollback $rollback): bool
    {
        return $user->is_active
            && $user->role->isSystemRole()
            && $rollback->isActive();
    }
}
