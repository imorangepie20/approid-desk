<?php

namespace Tests\Feature;

use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class WorkRequestListTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_inactive_users_are_forbidden_from_request_list(): void
    {
        $this->get('/requests')->assertRedirect(route('login'));

        $inactiveOperator = User::factory()->operator()->inactive()->create();

        $this->actingAs($inactiveOperator)
            ->get('/requests')
            ->assertForbidden();
    }

    public function test_operator_can_combine_every_request_filter(): void
    {
        $operator = User::factory()->operator()->create();
        $company = Company::factory()->create(['name' => '필터 고객사']);
        $project = Project::factory()->for($company)->create(['name' => '필터 프로젝트']);
        $otherProject = Project::factory()->for($company)->create(['name' => '다른 프로젝트']);

        $matching = $this->createRequest($company, $project, '필터 대상 긴급 오류', [
            'status' => WorkRequestStatus::InProgress,
            'type' => WorkRequestType::BugFix,
            'priority' => WorkRequestPriority::High,
            'requested_at' => '2026-10-02 09:00:00',
            'registered_at' => '2026-10-02 09:00:00',
        ]);
        $this->createRequest($company, $project, '필터 대상 접수 오류', [
            'status' => WorkRequestStatus::Received,
            'type' => WorkRequestType::BugFix,
            'priority' => WorkRequestPriority::High,
            'requested_at' => '2026-10-02 10:00:00',
            'registered_at' => '2026-10-02 10:00:00',
        ]);
        $this->createRequest($company, $otherProject, '필터 대상 다른 프로젝트', [
            'status' => WorkRequestStatus::InProgress,
            'type' => WorkRequestType::BugFix,
            'priority' => WorkRequestPriority::High,
            'requested_at' => '2026-10-02 11:00:00',
            'registered_at' => '2026-10-02 11:00:00',
        ]);
        $this->createRequest($company, $project, '필터 대상 과거 오류', [
            'status' => WorkRequestStatus::InProgress,
            'type' => WorkRequestType::BugFix,
            'priority' => WorkRequestPriority::High,
            'requested_at' => '2026-09-30 09:00:00',
            'registered_at' => '2026-09-30 09:00:00',
            'late_entry_reason' => '과거 접수 요청',
        ]);

        $response = $this->actingAs($operator)->get(route('requests.index', [
            'search' => '긴급 오류',
            'status' => WorkRequestStatus::InProgress->value,
            'type' => WorkRequestType::BugFix->value,
            'priority' => WorkRequestPriority::High->value,
            'company_id' => $company->id,
            'project_id' => $project->id,
            'requested_from' => '2026-10-01',
            'requested_to' => '2026-10-02',
        ]));

        $response
            ->assertOk()
            ->assertViewIs('requests.index')
            ->assertViewHas('workRequests', function (LengthAwarePaginator $requests) use ($matching): bool {
                return $requests->total() === 1 && $requests->first()?->is($matching);
            })
            ->assertSee('필터 대상 긴급 오류')
            ->assertDontSee('필터 대상 접수 오류')
            ->assertDontSee('필터 대상 다른 프로젝트')
            ->assertDontSee('필터 대상 과거 오류');
    }

    public function test_customer_request_list_stays_inside_its_company_and_hides_company_filter(): void
    {
        $company = Company::factory()->create(['name' => '자사 고객사']);
        $otherCompany = Company::factory()->create(['name' => '타사 고객사']);
        $customer = User::factory()->customerUser()->for($company)->create();
        $project = Project::factory()->for($company)->create(['name' => '자사 프로젝트']);
        $otherProject = Project::factory()->for($otherCompany)->create(['name' => '타사 프로젝트']);
        $this->createRequest($company, $project, '자사 요청');
        $this->createRequest($otherCompany, $otherProject, '타사 요청');

        $this->actingAs($customer)
            ->get(route('requests.index', ['company_id' => $otherCompany->id]))
            ->assertOk()
            ->assertSee('자사 요청')
            ->assertSee('자사 고객사')
            ->assertDontSee('타사 요청')
            ->assertDontSee('타사 고객사')
            ->assertDontSee('name="company_id"', false);
    }

    public function test_company_filter_limits_project_options_and_rejects_a_mismatched_project_filter(): void
    {
        $operator = User::factory()->operator()->create();
        $company = Company::factory()->create(['name' => '선택 고객사']);
        $otherCompany = Company::factory()->create(['name' => '다른 고객사']);
        $project = Project::factory()->for($company)->create(['name' => '선택 프로젝트']);
        $otherProject = Project::factory()->for($otherCompany)->create(['name' => '노출 금지 프로젝트']);
        $this->createRequest($company, $project, '정상 조합 요청');

        $this->actingAs($operator)
            ->get(route('requests.index', [
                'company_id' => $company->id,
                'project_id' => $otherProject->id,
            ]))
            ->assertOk()
            ->assertSee('선택 프로젝트')
            ->assertDontSee('노출 금지 프로젝트')
            ->assertDontSee('정상 조합 요청');
    }

    public function test_request_list_renders_desktop_and_mobile_contracts_with_core_metadata(): void
    {
        $operator = User::factory()->operator()->create();
        $company = Company::factory()->create(['name' => '표시 고객사']);
        $project = Project::factory()->for($company)->create(['name' => '표시 프로젝트']);
        $this->createRequest($company, $project, '반응형 표시 요청', [
            'status' => WorkRequestStatus::AwaitingReview,
            'type' => WorkRequestType::Maintenance,
            'priority' => WorkRequestPriority::High,
            'is_urgent' => true,
            'requested_at' => '2026-10-02 13:30:00',
            'registered_at' => '2026-10-02 13:30:00',
        ]);

        $this->actingAs($operator)
            ->get(route('requests.index'))
            ->assertOk()
            ->assertSee('data-test="request-table"', false)
            ->assertSee('data-test="request-card-list"', false)
            ->assertSee('반응형 표시 요청')
            ->assertSee('표시 고객사')
            ->assertSee('표시 프로젝트')
            ->assertSee('검수 대기')
            ->assertSee('높음')
            ->assertSee('2026.10.02');
    }

    public function test_request_list_shows_empty_state_and_reset_link_for_unmatched_filters(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->get(route('requests.index', ['search' => '존재하지 않는 요청']))
            ->assertOk()
            ->assertSee('조건에 맞는 요청이 없습니다.')
            ->assertSee('data-test="request-empty-state"', false)
            ->assertSee(route('requests.index'), false)
            ->assertSee('초기화');
    }

    public function test_request_navigation_is_connected_from_sidebar_dashboards_and_project_detail(): void
    {
        $company = Company::factory()->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $project = Project::factory()->for($company)->create();
        $this->createRequest($company, $project, '연결 요청');

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('requests.index'), false);

        $this->actingAs($customer)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(route('requests.index', ['project_id' => $project->id]), false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRequest(
        Company $company,
        Project $project,
        string $title,
        array $attributes = [],
    ): WorkRequest {
        return WorkRequest::factory()
            ->withSignedContract()
            ->for($company)
            ->for($project)
            ->create(array_merge(['title' => $title], $attributes));
    }
}
