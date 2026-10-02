<?php

namespace App\Actions;

use App\Enums\CompanyStatus;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AcceptUserInvitation
{
    public function handle(string $token, string $name, string $password): User
    {
        return DB::transaction(function () use ($token, $name, $password): User {
            $invitation = UserInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('accepted_at')
                ->whereNull('accepted_user_id')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->whereHas('company', fn (Builder $company): Builder => $company->where('status', CompanyStatus::Active->value))
                ->lockForUpdate()
                ->firstOrFail();

            $user = new User;
            $user->company_id = $invitation->company_id;
            $user->role = $invitation->role;
            $user->is_active = true;
            $user->name = trim($name);
            $user->email = $invitation->email;
            $user->email_verified_at = now();
            $user->password = $password;
            $user->save();

            $invitation->update([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ]);

            return $user;
        });
    }
}
