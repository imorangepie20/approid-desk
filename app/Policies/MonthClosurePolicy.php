<?php

namespace App\Policies;

use App\Models\MonthClosure;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class MonthClosurePolicy
{
    use ChecksCompanyScope;

    public function view(User $user, MonthClosure $record): bool
    {
        return $this->canAccessCompany($user, $record->company_id);
    }
}
