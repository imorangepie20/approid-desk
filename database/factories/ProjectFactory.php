<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => CompanyFactory::new(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'status' => ProjectStatus::Active,
            'site_url' => fake()->optional()->url(),
            'technical_notes' => null,
            'is_existing_site' => false,
            'source_code_secured' => false,
            'database_dump_secured' => false,
        ];
    }

    public function existingSite(
        bool $sourceCodeSecured = true,
        bool $databaseDumpSecured = true,
    ): static {
        return $this->state(fn (): array => [
            'is_existing_site' => true,
            'source_code_secured' => $sourceCodeSecured,
            'database_dump_secured' => $databaseDumpSecured,
        ]);
    }

    public function onHold(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::OnHold,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Archived,
        ]);
    }
}
