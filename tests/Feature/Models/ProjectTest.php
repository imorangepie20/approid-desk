<?php

namespace Tests\Feature\Models;

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_factory_creates_an_active_project_for_one_company(): void
    {
        $project = Project::factory()->create();

        $this->assertInstanceOf(Company::class, $project->company);
        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertFalse($project->is_existing_site);
        $this->assertFalse($project->source_code_secured);
        $this->assertFalse($project->database_dump_secured);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'company_id' => $project->company_id,
            'status' => ProjectStatus::Active->value,
        ]);
    }

    public function test_company_projects_relationship_does_not_include_another_company(): void
    {
        $company = Company::factory()->create();
        $ownProject = Project::factory()->for($company)->create();
        Project::factory()->create();

        $projects = $company->projects;

        $this->assertCount(1, $projects);
        $this->assertTrue($projects->first()->is($ownProject));
    }

    public function test_existing_site_materials_and_optional_project_details_are_stored(): void
    {
        $project = Project::factory()->existingSite()->create([
            'description' => null,
            'site_url' => 'https://customer.example',
            'technical_notes' => null,
        ]);

        $this->assertTrue($project->is_existing_site);
        $this->assertTrue($project->source_code_secured);
        $this->assertTrue($project->database_dump_secured);
        $this->assertNull($project->description);
        $this->assertSame('https://customer.example', $project->site_url);
        $this->assertNull($project->technical_notes);
    }

    public function test_company_with_project_cannot_be_physically_deleted(): void
    {
        $company = Company::factory()->create();
        Project::factory()->for($company)->create();

        $this->expectException(QueryException::class);

        $company->delete();
    }

    public function test_project_secret_payload_is_encrypted_at_rest(): void
    {
        $project = Project::factory()->create();
        $payload = [
            'login_url' => 'https://customer.example/admin',
            'username' => 'maintenance-user',
            'password' => 'plain-secret-must-not-leak',
        ];

        $secret = $project->secrets()->create([
            'label' => '운영 관리자 계정',
            'secret_data' => $payload,
        ]);

        $rawPayload = DB::table('project_secrets')
            ->where('id', $secret->id)
            ->value('secret_data');

        $this->assertSame($payload, $secret->fresh()->secret_data);
        $this->assertIsString($rawPayload);
        $this->assertStringNotContainsString('maintenance-user', $rawPayload);
        $this->assertStringNotContainsString('plain-secret-must-not-leak', $rawPayload);
    }
}
