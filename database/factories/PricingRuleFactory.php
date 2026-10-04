<?php

namespace Database\Factories;

use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestType;
use App\Models\PricingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PricingRule> */
class PricingRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'work_type' => WorkRequestType::Feature,
            'difficulty' => WorkDifficulty::Normal,
            'hourly_rate' => 60000,
            'urgent_surcharge_bps' => 2500,
            'urgent_criteria' => '테스트용 긴급 요청 가산 기준',
            'valid_from' => today(),
            'valid_until' => null,
        ];
    }
}
