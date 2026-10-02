<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ProjectSecret;
use App\Models\User;

class ProjectSecretPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewSecrets($user);
    }

    public function view(User $user, ProjectSecret $projectSecret): bool
    {
        return $this->canViewSecrets($user);
    }

    private function canViewSecrets(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::SuperAdmin;
    }
}
