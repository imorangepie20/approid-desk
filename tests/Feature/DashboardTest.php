<?php

namespace Tests\Feature;

use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_system_user_sees_operation_dashboard_metrics(): void
    {
        $company = Company::factory()->create();
        Company::factory()->inactive()->create();
        $project = Project::factory()->for($company)->create();
        $operator = User::factory()->operator()->create();

        $this->createRequest($company, $project, WorkRequestStatus::Received, false, '신규 일반 요청');
        $this->createRequest($company, $project, WorkRequestStatus::Received, true, '신규 긴급 요청');
        $this->createRequest($company, $project, WorkRequestStatus::InProgress, true, '진행 긴급 요청');
        $this->createRequest($company, $project, WorkRequestStatus::InProgress, false, '진행 일반 요청');
        $this->createRequest($company, $project, WorkRequestStatus::Completed, true, '완료된 긴급 요청');
        $this->createRequest($company, $project, WorkRequestStatus::Cancelled, true, '취소된 긴급 요청');

        $response = $this->actingAs($operator)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertViewIs('dashboard.operator')
            ->assertViewHas('metrics', [
                'activeCompanies' => 1,
                'newRequests' => 2,
                'urgentRequests' => 2,
                'inProgressRequests' => 2,
            ])
            ->assertSee('운영 대시보드')
            ->assertSee('활성 고객사')
            ->assertSee('신규 요청')
            ->assertSee('긴급 요청')
            ->assertSee('진행 중')
            ->assertSee('신규 긴급 요청')
            ->assertSee('진행 긴급 요청')
            ->assertDontSee('완료된 긴급 요청')
            ->assertDontSee('취소된 긴급 요청');
    }

    public function test_customer_sees_only_its_company_dashboard_data(): void
    {
        $company = Company::factory()->create(['name' => '내 고객사']);
        $otherCompany = Company::factory()->create(['name' => '다른 고객사']);
        $customer = User::factory()->customerAdmin()->for($company)->create();
        $activeProject = Project::factory()->for($company)->create(['name' => '내 활성 프로젝트']);
        Project::factory()->for($company)->archived()->create(['name' => '내 보관 프로젝트']);
        $otherProject = Project::factory()->for($otherCompany)->create(['name' => '다른 고객사 프로젝트']);

        $this->createRequest($company, $activeProject, WorkRequestStatus::Received, false, '내 접수 요청');
        $this->createRequest($company, $activeProject, WorkRequestStatus::AwaitingApproval, false, '내 승인 대기 요청');
        $this->createRequest($company, $activeProject, WorkRequestStatus::AwaitingReview, false, '내 검수 대기 요청');
        $this->createRequest($company, $activeProject, WorkRequestStatus::Completed, false, '내 완료 요청');
        $this->createRequest($otherCompany, $otherProject, WorkRequestStatus::Received, true, '다른 고객사 긴급 요청');

        $response = $this->actingAs($customer)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertViewIs('dashboard.customer')
            ->assertViewHas('metrics', [
                'activeProjects' => 1,
                'openRequests' => 3,
                'awaitingApprovalRequests' => 1,
                'awaitingReviewRequests' => 1,
            ])
            ->assertSee('내 고객사')
            ->assertSee('내 활성 프로젝트')
            ->assertSee('내 보관 프로젝트')
            ->assertSee('내 접수 요청')
            ->assertSee('내 승인 대기 요청')
            ->assertSee('내 검수 대기 요청')
            ->assertSee('내 완료 요청')
            ->assertDontSee('운영 대시보드')
            ->assertDontSee('다른 고객사 프로젝트')
            ->assertDontSee('다른 고객사 긴급 요청');
    }

    public function test_customer_recent_requests_are_limited_to_eight_in_newest_first_order(): void
    {
        $company = Company::factory()->create();
        $project = Project::factory()->for($company)->create();
        $customer = User::factory()->customerUser()->for($company)->create();

        foreach (range(1, 9) as $day) {
            $this->createRequest(
                $company,
                $project,
                WorkRequestStatus::Received,
                false,
                "고객 요청 {$day}",
            )->update([
                'requested_at' => now()->subDays(9 - $day),
                'late_entry_reason' => '대시보드 최신순 검증용 과거 요청',
            ]);
        }

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewIs('dashboard.customer')
            ->assertViewHas('recentRequests', function (Collection $requests): bool {
                return $requests->count() === 8
                    && $requests->first()?->title === '고객 요청 9'
                    && $requests->last()?->title === '고객 요청 2';
            })
            ->assertSee('고객 요청 9')
            ->assertSee('고객 요청 2')
            ->assertDontSee('고객 요청 1');
    }

    public function test_inactive_account_cannot_open_the_dashboard(): void
    {
        $inactiveUser = User::factory()->operator()->inactive()->create();

        $this->actingAs($inactiveUser)
            ->get(route('dashboard'))
            ->assertForbidden();
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
