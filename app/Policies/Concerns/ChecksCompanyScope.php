<?php

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\User;

trait ChecksCompanyScope
{
    protected function canAccessCompany(User $user, int $companyId): bool
    {
        if (! $user->canAccessWorkspace()) {
            return false;
        }

        if ($user->role->isSystemRole()) {
            return true;
        }

        return $user->company_id !== null && $user->company_id === $companyId;
    }

    protected function canAccessCompanyListings(User $user): bool
    {
        return $user->canAccessWorkspace()
            && ($user->role->isSystemRole() || $user->company_id !== null);
    }

    protected function hasCompanyPermission(User $user, Permission $permission, int $companyId): bool
    {
        return $user->role->hasPermission($permission)
            && $this->canAccessCompany($user, $companyId);
    }
}
