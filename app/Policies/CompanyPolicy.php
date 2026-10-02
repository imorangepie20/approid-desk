<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class CompanyPolicy
{
    use ChecksCompanyScope;

    public function viewAny(User $user): bool
    {
        return $this->canAccessCompanyListings($user);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->canAccessCompany($user, $company->id);
    }

    public function create(User $user): bool
    {
        return $user->canAccessWorkspace()
            && $user->role->hasPermission(Permission::ManageCompanies);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->role->hasPermission(Permission::ManageCompanies)
            && $this->canAccessCompany($user, $company->id);
    }
}
