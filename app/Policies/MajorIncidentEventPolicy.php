<?php

namespace App\Policies;

use App\Models\MajorIncidentEvent;
use App\Models\User;
use App\Models\WorkRequest;
use App\Policies\Concerns\ChecksCompanyScope;

class MajorIncidentEventPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, MajorIncidentEvent $event): bool
    {
        return $this->canAccessCompany($user, $event->company_id);
    }

    public function create(User $user, WorkRequest $workRequest): bool
    {
        return $user->is_active
            && $user->role->isSystemRole()
            && $workRequest->isMajorIncident();
    }
}
