<?php

namespace Tests\Feature\Models;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\WorkRequest;
use App\Models\WorkRequestActivity;
use App\Models\WorkRequestComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_lists_only_records_from_their_company(): void
    {
        $own = $this->createCompanyRecords('자사');
        $this->createCompanyRecords('타사');

        $this->assertSame([$own['company']->id], Company::query()->visibleTo($own['user'])->pluck('id')->all());
        $this->assertSame([$own['project']->id], Project::query()->visibleTo($own['user'])->pluck('id')->all());
        $this->assertSame([$own['request']->id], WorkRequest::query()->visibleTo($own['user'])->pluck('id')->all());
        $this->assertSame([$own['comment']->id], WorkRequestComment::query()->visibleTo($own['user'])->pluck('id')->all());
        $this->assertSame([$own['invitation']->id], UserInvitation::query()->visibleTo($own['user'])->pluck('id')->all());
        $this->assertSame([$own['user']->id], User::query()->visibleTo($own['user'])->pluck('id')->all());

        $activityCompanyIds = WorkRequestActivity::query()
            ->visibleTo($own['user'])
            ->distinct()
            ->pluck('company_id')
            ->all();

        $this->assertSame([$own['company']->id], $activityCompanyIds);
    }

    public function test_search_dropdown_and_autocomplete_exclude_other_companies(): void
    {
        $own = $this->createCompanyRecords('검색 대상');
        $other = $this->createCompanyRecords('검색 대상');

        $projectOptions = Project::query()
            ->visibleTo($own['user'])
            ->where('name', 'like', '%검색 대상%')
            ->pluck('name', 'id');
        $requestMatches = WorkRequest::query()
            ->visibleTo($own['user'])
            ->where('title', 'like', '%검색 대상%')
            ->pluck('id');

        $this->assertSame([$own['project']->id], $projectOptions->keys()->all());
        $this->assertFalse($projectOptions->has($other['project']->id));
        $this->assertSame([$own['request']->id], $requestMatches->all());
        $this->assertNotContains($other['request']->id, $requestMatches);
    }

    public function test_dashboard_aggregate_counts_only_the_customer_company(): void
    {
        $own = $this->createCompanyRecords('집계 자사');
        $this->createCompanyRecords('집계 타사');
        WorkRequest::factory()
            ->for($own['company'])
            ->for($own['project'])
            ->for($own['user'], 'submitter')
            ->create(['title' => '집계 자사 추가 요청']);

        $this->assertSame(2, WorkRequest::query()->visibleTo($own['user'])->count());
        $this->assertSame(
            1,
            WorkRequest::query()->visibleTo($own['user'])->distinct()->count('project_id'),
        );
    }

    public function test_active_system_account_can_query_all_company_records(): void
    {
        $this->createCompanyRecords('첫 번째');
        $this->createCompanyRecords('두 번째');
        $operator = User::factory()->operator()->create();

        $this->assertSame(2, Company::query()->visibleTo($operator)->count());
        $this->assertSame(2, Project::query()->visibleTo($operator)->count());
        $this->assertSame(2, WorkRequest::query()->visibleTo($operator)->count());
        $this->assertSame(2, WorkRequestComment::query()->visibleTo($operator)->count());
        $this->assertSame(2, UserInvitation::query()->visibleTo($operator)->count());
    }

    public function test_inactive_account_receives_empty_lists_and_aggregates(): void
    {
        $own = $this->createCompanyRecords('비활성');
        $own['user']->forceFill(['is_active' => false])->save();
        $inactiveUser = $own['user']->fresh();

        $this->assertSame(0, Company::query()->visibleTo($inactiveUser)->count());
        $this->assertSame(0, Project::query()->visibleTo($inactiveUser)->count());
        $this->assertSame(0, WorkRequest::query()->visibleTo($inactiveUser)->count());
        $this->assertSame(0, WorkRequestComment::query()->visibleTo($inactiveUser)->count());
        $this->assertSame(0, WorkRequestActivity::query()->visibleTo($inactiveUser)->count());
        $this->assertSame(0, UserInvitation::query()->visibleTo($inactiveUser)->count());
        $this->assertSame(0, User::query()->visibleTo($inactiveUser)->count());
    }

    public function test_project_secret_lists_follow_the_secret_policy_boundary(): void
    {
        $first = $this->createCompanyRecords('보안 첫 번째');
        $this->createCompanyRecords('보안 두 번째');
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->assertSame(2, ProjectSecret::query()->visibleTo($superAdmin)->count());
        $this->assertSame(0, ProjectSecret::query()->visibleTo($operator)->count());
        $this->assertSame(0, ProjectSecret::query()->visibleTo($first['user'])->count());
    }

    /**
     * @return array{
     *     company: Company,
     *     user: User,
     *     project: Project,
     *     request: WorkRequest,
     *     comment: WorkRequestComment,
     *     invitation: UserInvitation,
     * }
     */
    private function createCompanyRecords(string $label): array
    {
        $company = Company::factory()->create(['name' => "{$label} 고객사"]);
        $user = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create(['name' => "{$label} 프로젝트"]);
        $request = WorkRequest::factory()
            ->for($company)
            ->for($project)
            ->for($user, 'submitter')
            ->create(['title' => "{$label} 요청"]);
        $comment = WorkRequestComment::factory()
            ->for($company)
            ->for($request)
            ->for($user, 'author')
            ->create();
        $invitation = UserInvitation::factory()->for($company)->create();
        ProjectSecret::factory()->for($project)->create();

        return compact('company', 'user', 'project', 'request', 'comment', 'invitation');
    }
}
