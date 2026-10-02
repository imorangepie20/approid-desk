<?php

namespace App\Actions;

use App\Data\CreatedUserInvitation;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateUserInvitation
{
    public function handle(
        User $inviter,
        Company $company,
        string $email,
        UserRole $role,
        CarbonInterface $expiresAt,
    ): CreatedUserInvitation {
        Gate::forUser($inviter)->authorize('create', [UserInvitation::class, $company]);

        $email = Str::lower(trim($email));
        $errors = [];

        if ($company->status !== CompanyStatus::Active) {
            $errors['company'] = '활성 고객사에만 사용자를 초대할 수 있습니다.';
        }

        if (! $role->isCustomerRole()) {
            $errors['role'] = '고객사 역할만 초대에 지정할 수 있습니다.';
        }

        if ($expiresAt->isPast()) {
            $errors['expires_at'] = '초대 만료시각은 현재보다 이후여야 합니다.';
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '올바른 이메일 주소를 입력해야 합니다.';
        } elseif (User::query()->where('email', $email)->exists()) {
            $errors['email'] = '이미 가입된 이메일 주소입니다.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($company, $email, $expiresAt, $inviter, $role): CreatedUserInvitation {
            UserInvitation::query()
                ->where('company_id', $company->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->update(['revoked_at' => now()]);

            $token = Str::random(64);
            $invitation = UserInvitation::query()->create([
                'company_id' => $company->id,
                'invited_by' => $inviter->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
            ]);

            return new CreatedUserInvitation($invitation, $token);
        });
    }
}
