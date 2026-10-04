<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class WeeklyProgressReportRecipients
{
    /** @return Collection<int, User> */
    public function for(Company $company): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($company): void {
                $query->whereIn('role', [UserRole::SuperAdmin->value, UserRole::Operator->value])
                    ->orWhere(function ($query) use ($company): void {
                        $query->where('company_id', $company->id)
                            ->where('role', UserRole::CustomerAdmin->value);
                    });
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->canAccessWorkspace())
            ->values();
    }
}
