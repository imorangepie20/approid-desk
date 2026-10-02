<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\UserInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserInvitation>
 */
class UserInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => CompanyFactory::new(),
            'invited_by' => UserFactory::new()->operator(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::CustomerUser,
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'accepted_user_id' => null,
            'revoked_at' => null,
        ];
    }
}
