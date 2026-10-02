<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use App\Policies\Concerns\ChecksCompanyScope;

class UserInvitationPolicy
{
    use ChecksCompanyScope;

    public function viewAny(User $user): bool
    {
        return $user->is_active
            && $user->role->hasPermission(Permission::ManageCompanyUsers);
    }

    public function view(User $user, UserInvitation $invitation): bool
    {
        return $this->hasCompanyPermission(
            $user,
            Permission::ManageCompanyUsers,
            $invitation->company_id,
        );
    }

    public function create(User $user, Company $company): bool
    {
        return $this->hasCompanyPermission(
            $user,
            Permission::ManageCompanyUsers,
            $company->id,
        );
    }

    public function delete(User $user, UserInvitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }
}
