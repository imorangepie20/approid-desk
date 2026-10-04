<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ContractMonth;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class ContractMonthPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, ContractMonth $month): bool
    {
        return $this->canAccessCompany($user, $month->company_id);
    }

    public function close(User $user, ContractMonth $month): bool
    {
        return $this->hasCompanyPermission($user, Permission::CloseContractMonths, $month->company_id);
    }

    public function adjust(User $user, ContractMonth $month): bool
    {
        return $this->close($user, $month);
    }
}
