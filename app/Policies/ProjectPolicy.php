<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class ProjectPolicy
{
    use ChecksCompanyScope;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCompanyListings($user);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->canAccessCompany($user, $project->company_id);
    }
}
