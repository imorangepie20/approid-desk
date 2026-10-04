<?php

namespace App\Policies;

use App\Models\MonthAdjustment;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class MonthAdjustmentPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, MonthAdjustment $record): bool
    {
        return $this->canAccessCompany($user, $record->company_id);
    }
}
