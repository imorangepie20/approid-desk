<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_project_pages(): void
    {
        $project = Project::factory()->create();

        $this->get('/projects')->assertRedirect(route('login'));
        $this->get("/projects/{$project->id}")->assertRedirect(route('login'));
    }

    public function test_operator_can_filter_projects_by_search_status_and_company(): void
    {
        $operator = User::factory()->operator()->create();
        $company = Company::factory()->create(['name' => '검색 고객사']);
        $otherCompany = Company::factory()->create(['name' => '다른 고객사']);
        $matching = Project::factory()->for($company)->create(['name' => '검색 활성 프로젝트']);
        Project::factory()->for($company)->onHold()->create(['name' => '검색 보류 프로젝트']);
        Project::factory()->for($otherCompany)->create(['name' => '검색 타사 프로젝트']);

        $response = $this->actingAs($operator)->get(route('projects.index', [
            'search' => '검색',
            'status' => ProjectStatus::Active->value,
            'company_id' => $company->id,
        ]));

        $response
            ->assertOk()
            ->assertViewIs('projects.index')
            ->assertViewHas('projects', function (LengthAwarePaginator $projects) use ($matching): bool {
                return $projects->total() === 1 && $projects->first()?->is($matching);
            })
            ->assertSee('검색 활성 프로젝트')
            ->assertDontSee('검색 보류 프로젝트')
            ->assertDontSee('검색 타사 프로젝트');
    }

    public function test_customer_list_is_limited_to_its_company_even_with_another_company_filter(): void
    {
        $company = Company::factory()->create(['name' => '자사 고객사']);
        $otherCompany = Company::factory()->create(['name' => '타사 고객사']);
        $customer = User::factory()->customerUser()->for($company)->create();
        Project::factory()->for($company)->create(['name' => '자사 프로젝트']);
        Project::factory()->for($otherCompany)->create(['name' => '타사 프로젝트']);

        $this->actingAs($customer)
            ->get(route('projects.index', ['company_id' => $otherCompany->id]))
            ->assertOk()
            ->assertSee('자사 프로젝트')
            ->assertSee('자사 고객사')
            ->assertDontSee('타사 프로젝트')
            ->assertDontSee('타사 고객사');
    }

    public function test_project_detail_shows_metadata_handover_state_and_request_summary_without_secrets(): void
    {
        $company = Company::factory()->create(['name' => '상세 고객사']);
        $operator = User::factory()->operator()->create();
        $project = Project::factory()->for($company)->existingSite(true, false)->create([
            'name' => '상세 프로젝트',
            'description' => '프로젝트 소개 문구',
            'site_url' => 'https://project.example',
            'technical_notes' => 'PHP 8.3 기반 운영 환경',
        ]);
        ProjectSecret::factory()->for($project)->create([
            'label' => '노출되면 안 되는 계정',
            'secret_data' => ['password' => 'top-secret'],
        ]);
        $this->createRequest($company, $project, WorkRequestStatus::Received, false, '접수 요청');
        $this->createRequest($company, $project, WorkRequestStatus::InProgress, true, '긴급 진행 요청');
        $this->createRequest($company, $project, WorkRequestStatus::Completed, false, '완료 요청');

        $this->actingAs($operator)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertViewIs('projects.show')
            ->assertViewHas('metrics', [
                'totalRequests' => 3,
                'openRequests' => 2,
                'completedRequests' => 1,
                'urgentRequests' => 1,
            ])
            ->assertSee('상세 프로젝트')
            ->assertSee('상세 고객사')
            ->assertSee('프로젝트 소개 문구')
            ->assertSee('https://project.example')
            ->assertSee('PHP 8.3 기반 운영 환경')
            ->assertSee('소스 코드 확보')
            ->assertSee('DB 덤프 미확보')
            ->assertSee('긴급 진행 요청')
            ->assertDontSee('노출되면 안 되는 계정')
            ->assertDontSee('top-secret');
    }

    public function test_project_detail_recent_requests_are_limited_to_ten_in_newest_first_order(): void
    {
        $company = Company::factory()->create();
        $customer = User::factory()->customerAdmin()->for($company)->create();
        $project = Project::factory()->for($company)->create();

        foreach (range(1, 11) as $day) {
            $this->createRequest($company, $project, WorkRequestStatus::Received, false, "프로젝트 요청 {$day}")
                ->update([
                    'requested_at' => now()->subDays(11 - $day),
                    'late_entry_reason' => '최신순 검증용 과거 요청',
                ]);
        }

        $this->actingAs($customer)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertViewHas('recentRequests', function (Collection $requests): bool {
                return $requests->count() === 10
                    && $requests->first()?->title === '프로젝트 요청 11'
                    && $requests->last()?->title === '프로젝트 요청 2';
            })
            ->assertSeeInOrder(['프로젝트 요청 11', '프로젝트 요청 10'])
            ->assertSee('프로젝트 요청 2');
    }

    public function test_customer_cannot_view_another_company_project_and_inactive_users_are_blocked(): void
    {
        $company = Company::factory()->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $otherProject = Project::factory()->create();
        $inactiveOperator = User::factory()->operator()->inactive()->create();

        $this->actingAs($customer)
            ->get(route('projects.show', $otherProject))
            ->assertForbidden();

        $this->actingAs($inactiveOperator)
            ->get(route('projects.index'))
            ->assertForbidden();

        $this->actingAs($inactiveOperator)
            ->get(route('projects.show', $otherProject))
            ->assertForbidden();
    }

    public function test_project_navigation_and_dashboard_cards_link_to_project_pages(): void
    {
        $company = Company::factory()->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $project = Project::factory()->for($company)->create(['name' => '연결 프로젝트']);

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('projects.index'), false)
            ->assertSee(route('projects.show', $project), false);
    }

    private function createRequest(
        Company $company,
        Project $project,
        WorkRequestStatus $status,
        bool $urgent,
        string $title,
    ): WorkRequest {
        return WorkRequest::factory()
            ->withSignedContract()
            ->for($company)
            ->for($project)
            ->create([
                'status' => $status,
                'is_urgent' => $urgent,
                'title' => $title,
            ]);
    }
}
