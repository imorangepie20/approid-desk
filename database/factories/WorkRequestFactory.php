<?php

namespace Database\Factories;

use App\Enums\IntakeChannel;
use App\Enums\UserRole;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Models\WorkRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkRequest>
 */
class WorkRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => CompanyFactory::new(),
            'project_id' => fn (array $attributes): int => (int) ProjectFactory::new()->createOne([
                'company_id' => $attributes['company_id'],
            ])->getKey(),
            'submitted_by' => fn (array $attributes): int => (int) UserFactory::new()->createOne([
                'company_id' => $attributes['company_id'],
                'role' => UserRole::CustomerUser,
            ])->getKey(),
            'assigned_to' => null,
            'parent_request_id' => null,
            'title' => fake()->sentence(6),
            'requirements' => fake()->paragraphs(2, true),
            'type' => WorkRequestType::Feature,
            'priority' => WorkRequestPriority::Normal,
            'is_urgent' => false,
            'desired_due_date' => null,
            'intake_channel' => IntakeChannel::Web,
            'source_reference' => null,
            'intake_summary' => null,
            'status' => WorkRequestStatus::Received,
            'requested_at' => now(),
            'registered_at' => now(),
            'late_entry_reason' => null,
        ];
    }

    public function urgent(): static
    {
        return $this->state(fn (): array => [
            'priority' => WorkRequestPriority::High,
            'is_urgent' => true,
        ]);
    }

    public function withSignedContract(): static
    {
        return $this->state(fn (): array => [
            'service_contract_id' => fn (array $attributes): int => ServiceContractFactory::new()->signed()->createOne([
                'company_id' => $attributes['company_id'],
            ])->id,
        ]);
    }

    public function manual(IntakeChannel $channel = IntakeChannel::Phone): static
    {
        return $this->state(fn (): array => [
            'intake_channel' => $channel,
            'source_reference' => '고객 연락 원문 또는 위치',
            'intake_summary' => '운영자가 정리한 요청 내용',
        ]);
    }
}
