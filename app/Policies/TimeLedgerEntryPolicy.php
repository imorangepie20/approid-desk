<?php

namespace App\Policies;

use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksCompanyScope;

class TimeLedgerEntryPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, TimeLedgerEntry $entry): bool
    {
        return $this->canAccessCompany($user, $entry->company_id);
    }
}
