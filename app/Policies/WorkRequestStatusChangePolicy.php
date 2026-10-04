<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkRequestStatusChange;
use App\Policies\Concerns\ChecksCompanyScope;

class WorkRequestStatusChangePolicy
{
    use ChecksCompanyScope;

    public function view(User $user, WorkRequestStatusChange $change): bool
    {
        return $this->canAccessCompany($user, $change->company_id);
    }
}
