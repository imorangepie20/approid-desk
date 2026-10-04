<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\WorkLogStatus;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use App\Policies\Concerns\ChecksCompanyScope;

class WorkLogPolicy
{
    use ChecksCompanyScope;

    public function create(User $user, WorkRequest $request): bool
    {
        return $this->hasCompanyPermission($user, Permission::LogWorkTime, $request->company_id);
    }

    public function view(User $user, WorkLog $log): bool
    {
        return $this->canAccessCompany($user, $log->company_id)
            && ($user->role->isSystemRole() || $log->status === WorkLogStatus::Confirmed);
    }

    public function update(User $user, WorkLog $log): bool
    {
        return $this->hasCompanyPermission($user, Permission::LogWorkTime, $log->company_id)
            && $log->status === WorkLogStatus::Draft
            && ($user->id === $log->worker_id || $user->role === UserRole::SuperAdmin);
    }

    public function confirm(User $user, WorkLog $log): bool
    {
        return $this->hasCompanyPermission($user, Permission::LogWorkTime, $log->company_id)
            && ($user->id === $log->worker_id || $user->role === UserRole::SuperAdmin);
    }

    public function cancelUsage(User $user, WorkLog $log): bool
    {
        return $this->hasCompanyPermission($user, Permission::CloseContractMonths, $log->company_id)
            && $log->status === WorkLogStatus::Confirmed;
    }
}
