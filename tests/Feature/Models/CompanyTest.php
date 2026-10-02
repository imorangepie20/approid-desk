<?php

namespace Tests\Feature\Models;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_factory_creates_an_active_company_by_default(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(CompanyStatus::Active, $company->status);
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'status' => CompanyStatus::Active->value,
        ]);
    }

    public function test_active_scope_excludes_inactive_companies(): void
    {
        $active = Company::factory()->create();
        Company::factory()->inactive()->create();

        $companies = Company::query()->active()->get();

        $this->assertCount(1, $companies);
        $this->assertTrue($companies->first()->is($active));
    }

    public function test_company_contact_information_and_notes_are_optional(): void
    {
        $company = Company::factory()->create([
            'primary_contact_name' => null,
            'primary_contact_email' => null,
            'primary_contact_phone' => null,
            'notes' => null,
        ]);

        $this->assertNull($company->primary_contact_name);
        $this->assertNull($company->primary_contact_email);
        $this->assertNull($company->primary_contact_phone);
        $this->assertNull($company->notes);
    }
}
