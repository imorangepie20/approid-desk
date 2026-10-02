<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'status' => CompanyStatus::Active,
            'primary_contact_name' => fake()->name(),
            'primary_contact_email' => fake()->companyEmail(),
            'primary_contact_phone' => fake()->phoneNumber(),
            'notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => CompanyStatus::Inactive,
        ]);
    }
}
