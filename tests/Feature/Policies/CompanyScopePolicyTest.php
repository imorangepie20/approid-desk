<?php

namespace Tests\Feature\Policies;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestActivity;
use App\Models\WorkRequestComment;
use App\Policies\CompanyPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectSecretPolicy;
use App\Policies\WorkRequestActivityPolicy;
use App\Policies\WorkRequestCommentPolicy;
use App\Policies\WorkRequestPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CompanyScopePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_scoped_models_discover_their_policies(): void
    {
        $expectedPolicies = [
            Company::class => CompanyPolicy::class,
            Project::class => ProjectPolicy::class,
            ProjectSecret::class => ProjectSecretPolicy::class,
            WorkRequest::class => WorkRequestPolicy::class,
            WorkRequestComment::class => WorkRequestCommentPolicy::class,
            WorkRequestActivity::class => WorkRequestActivityPolicy::class,
        ];

        foreach ($expectedPolicies as $model => $policy) {
            $this->assertInstanceOf($policy, Gate::getPolicyFor($model));
        }
    }

    public function test_customer_can_view_non_secret_records_from_their_company(): void
    {
        [$customer, $company, $project, $request, $comment, $activity] = $this->companyRecords();

        foreach ([$company, $project, $request, $comment, $activity] as $record) {
            $this->assertTrue(Gate::forUser($customer)->allows('view', $record));
        }
    }

    public function test_customer_cannot_view_records_from_another_company(): void
    {
        $customer = User::factory()->create();
        [, $company, $project, $request, $comment, $activity] = $this->companyRecords();

        foreach ([$company, $project, $request, $comment, $activity] as $record) {
            $this->assertFalse(Gate::forUser($customer)->allows('view', $record));
        }
    }

    public function test_changing_project_or_request_route_identifier_cannot_cross_company_boundary(): void
    {
        [$customer, , $ownProject, $ownRequest] = $this->companyRecords();
        [, , $otherProject, $otherRequest] = $this->companyRecords();

        Route::middleware(['web', 'auth'])->get('/_policy/projects/{project}', fn (Project $project): string => (string) $project->getKey())
            ->middleware('can:view,project');
        Route::middleware(['web', 'auth'])->get('/_policy/requests/{workRequest}', fn (WorkRequest $workRequest): string => (string) $workRequest->getKey())
            ->middleware('can:view,workRequest');

        $this->actingAs($customer)->get("/_policy/projects/{$ownProject->id}")->assertOk();
        $this->actingAs($customer)->get("/_policy/requests/{$ownRequest->id}")->assertOk();
        $this->actingAs($customer)->get("/_policy/projects/{$otherProject->id}")->assertForbidden();
        $this->actingAs($customer)->get("/_policy/requests/{$otherRequest->id}")->assertForbidden();
    }

    public function test_inactive_accounts_cannot_view_company_records(): void
    {
        [$customer, $company, $project, $request, $comment, $activity] = $this->companyRecords();
        $customer->forceFill(['is_active' => false])->save();

        foreach ([$company, $project, $request, $comment, $activity] as $record) {
            $this->assertFalse(Gate::forUser($customer->fresh())->allows('view', $record));
        }
    }

    public function test_active_system_accounts_can_view_records_from_every_company(): void
    {
        $operator = User::factory()->operator()->create();
        [, $company, $project, $request, $comment, $activity] = $this->companyRecords();

        foreach ([$company, $project, $request, $comment, $activity] as $record) {
            $this->assertTrue(Gate::forUser($operator)->allows('view', $record));
        }
    }

    public function test_project_secrets_are_limited_to_active_super_administrators(): void
    {
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();
        $customer = User::factory()->for($project->company)->create();
        $inactiveSuperAdmin = User::factory()->superAdmin()->inactive()->create();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $secret));
        $this->assertFalse(Gate::forUser($operator)->allows('view', $secret));
        $this->assertFalse(Gate::forUser($customer)->allows('view', $secret));
        $this->assertFalse(Gate::forUser($inactiveSuperAdmin)->allows('view', $secret));
    }

    /**
     * @return array{User, Company, Project, WorkRequest, WorkRequestComment, WorkRequestActivity}
     */
    private function companyRecords(): array
    {
        $company = Company::factory()->create();
        $customer = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create();
        $request = WorkRequest::factory()
            ->for($company)
            ->for($project)
            ->for($customer, 'submitter')
            ->create();
        $comment = WorkRequestComment::factory()
            ->for($company)
            ->for($request)
            ->for($customer, 'author')
            ->create();
        $activity = WorkRequestActivity::factory()
            ->for($company)
            ->for($request)
            ->for($customer, 'actor')
            ->create();

        return [$customer, $company, $project, $request, $comment, $activity];
    }
}
