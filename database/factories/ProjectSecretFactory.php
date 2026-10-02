<?php

namespace Database\Factories;

use App\Models\ProjectSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectSecret>
 */
class ProjectSecretFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => ProjectFactory::new(),
            'label' => '운영 접속 정보',
            'secret_data' => [
                'login_url' => fake()->url(),
                'username' => fake()->userName(),
                'password' => fake()->password(16),
            ],
            'last_verified_at' => null,
        ];
    }
}
