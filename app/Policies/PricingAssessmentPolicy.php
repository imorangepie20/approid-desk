<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PricingAssessment;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class PricingAssessmentPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, PricingAssessment $assessment): bool
    {
        return $this->hasCompanyPermission($user, Permission::CreateEstimates, $assessment->company_id);
    }
}
