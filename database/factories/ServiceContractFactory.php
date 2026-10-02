<?php

namespace Database\Factories;

use App\Enums\ServiceContractStatus;
use App\Enums\ServiceContractType;
use App\Models\ServiceContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceContract> */
class ServiceContractFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => CompanyFactory::new(),
            'type' => ServiceContractType::Maintenance,
            'status' => ServiceContractStatus::Draft,
            'starts_on' => today(),
            'ends_on' => today()->addYear(),
        ];
    }

    public function signed(): static
    {
        return $this->state(fn (): array => [
            'status' => ServiceContractStatus::Active,
            'document_path' => 'contracts/'.fake()->uuid().'.pdf',
            'signature_confirmed_at' => now(),
            'signature_confirmed_by' => UserFactory::new()->operator(),
        ]);
    }
}
