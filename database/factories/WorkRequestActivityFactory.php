<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\WorkRequestActivityType;
use App\Models\WorkRequestActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkRequestActivity>
 */
class WorkRequestActivityFactory extends Factory
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
            'actor_id' => fn (array $attributes): int => (int) UserFactory::new()->createOne([
                'company_id' => $attributes['company_id'],
                'role' => UserRole::CustomerUser,
            ])->getKey(),
            'comment_id' => null,
            'type' => WorkRequestActivityType::RequestUpdated,
            'summary' => '요청 내용이 수정되었습니다.',
            'before_values' => ['title' => '변경 전 제목'],
            'after_values' => ['title' => '변경 후 제목'],
            'occurred_at' => now(),
        ];
    }
}
