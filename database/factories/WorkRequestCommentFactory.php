<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\WorkRequestComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkRequestComment>
 */
class WorkRequestCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => CompanyFactory::new(),
            'work_request_id' => fn (array $attributes): int => (int) WorkRequestFactory::new()->createOne([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'author_id' => fn (array $attributes): int => (int) UserFactory::new()->createOne([
                'company_id' => $attributes['company_id'],
                'role' => UserRole::CustomerUser,
            ])->getKey(),
            'body' => fake()->paragraph(),
        ];
    }
}
