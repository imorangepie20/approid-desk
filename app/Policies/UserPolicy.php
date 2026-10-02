<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class UserPolicy
{
    use ChecksCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->role->hasPermission(Permission::ManageCompanyUsers);
    }

    public function view(User $user, User $targetUser): bool
    {
        return $this->canManage($user, $targetUser);
    }

    public function update(User $user, User $targetUser): bool
    {
        return $this->canManage($user, $targetUser);
    }

    public function delete(User $user, User $targetUser): bool
    {
        return $this->canManage($user, $targetUser);
    }

    private function canManage(User $user, User $targetUser): bool
    {
        if (! $user->role->hasPermission(Permission::ManageCompanyUsers)) {
            return false;
        }

        if ($user->role->isSystemRole()) {
            return $user->is_active;
        }

        return $targetUser->company_id !== null
            && $this->canAccessCompany($user, $targetUser->company_id);
    }
}
