<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkRequestActivity;
use App\Policies\Concerns\ChecksCompanyScope;

class WorkRequestActivityPolicy
{
    use ChecksCompanyScope;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCompanyListings($user);
    }

    public function view(User $user, WorkRequestActivity $workRequestActivity): bool
    {
        return $this->canAccessCompany($user, $workRequestActivity->company_id);
    }
}
