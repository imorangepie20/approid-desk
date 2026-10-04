<?php

namespace App\Policies;

use App\Models\EstimateApproval;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class EstimateApprovalPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, EstimateApproval $approval): bool
    {
        return $this->canAccessCompany($user, $approval->company_id);
    }
}
