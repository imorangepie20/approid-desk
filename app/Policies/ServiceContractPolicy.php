<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServiceContract;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class ServiceContractPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, ServiceContract $contract): bool
    {
        return $this->canAccessCompany($user, $contract->company_id);
    }

    public function confirmSignature(User $user, ServiceContract $contract): bool
    {
        return $this->hasCompanyPermission($user, Permission::ManageContracts, $contract->company_id);
    }

    public function provideMonth(User $user, ServiceContract $contract): bool
    {
        return $this->hasCompanyPermission($user, Permission::ManageContracts, $contract->company_id);
    }
}
