<?php

namespace Tests\Feature;

use App\Actions\TransitionWorkRequest;
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
                'majorIncidents' => 2,
                'inProgressRequests' => 2,
                'awaitingApprovalRequests' => 0,
                'awaitingReviewRequests' => 0,
            ])
            ->assertSee('운영 대시보드')
            ->assertSee('활성 고객사')
            ->assertSee('신규 요청')
            ->assertSee('주요 업무 장애')
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
                'majorIncidents' => 0,
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

    public function test_customer_dashboard_prioritizes_only_its_open_incidents_in_oldest_first_order(): void
    {
        $company = Company::factory()->create();
        $project = Project::factory()->for($company)->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $oldest = $this->createRequest($company, $project, WorkRequestStatus::InProgress, true, '먼저 접수된 주요 장애');
        $oldest->update(['requested_at' => '2026-10-01 09:00:00', 'registered_at' => '2026-10-01 09:00:00']);
        $newer = $this->createRequest($company, $project, WorkRequestStatus::Received, true, '나중에 접수된 주요 장애');
        $newer->update(['requested_at' => '2026-10-02 09:00:00', 'registered_at' => '2026-10-02 09:00:00']);
        $this->createRequest($company, $project, WorkRequestStatus::Completed, true, '완료되어 제외할 장애');
        $foreign = WorkRequest::factory()->urgent()->create(['title' => '타사 주요 장애']);

        $this->actingAs($customer)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['majorIncidents'] === 2)
            ->assertViewHas('majorIncidents', fn (Collection $requests): bool => $requests->modelKeys() === [$oldest->id, $newer->id])
            ->assertSeeInOrder(['먼저 접수된 주요 장애', '나중에 접수된 주요 장애'])
            ->assertDontSee($foreign->title)
            ->assertSee(route('requests.index', ['major_incident' => 1]), false);
    }

    public function test_major_incident_dashboard_displays_overdue_and_upcoming_first_response_deadlines(): void
    {
        $this->travelTo('2026-10-04 10:30:00');
        $company = Company::factory()->create();
        $project = Project::factory()->for($company)->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $overdue = $this->createRequest($company, $project, WorkRequestStatus::Received, true, '기한이 지난 장애');
        $overdue->update(['requested_at' => '2026-10-04 09:00:00', 'registered_at' => '2026-10-04 09:00:00']);
        $upcoming = $this->createRequest($company, $project, WorkRequestStatus::Received, true, '기한 전 장애');
        $upcoming->update(['requested_at' => '2026-10-04 10:00:00', 'registered_at' => '2026-10-04 10:00:00']);

        $this->actingAs($customer)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([$overdue->title, '내부 목표 경과', '2026.10.04 10:00'])
            ->assertSeeInOrder([$upcoming->title, '목표 응답 대기', '2026.10.04 11:00'])
            ->assertSee('계약 보장 아님')
            ->assertDontSee('최초 응답 기한')
            ->assertSee('data-test="major-incident-response-target"', false);
    }

    public function test_customer_recent_requests_are_limited_to_eight_in_newest_first_order(): void
    {
        $company = Company::factory()->create();
        $project = Project::factory()->for($company)->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $newestRequestedAt = now()->startOfMinute();

        foreach (range(1, 9) as $day) {
            $requestedAt = $newestRequestedAt->copy()->subDays(9 - $day);
            $this->createRequest(
                $company,
                $project,
                WorkRequestStatus::Received,
                false,
                "고객 요청 {$day}",
            )->update([
                'requested_at' => $requestedAt,
                'registered_at' => $requestedAt->copy()->addMinute(),
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

    public function test_pending_counts_include_all_requests_but_lists_show_oldest_five_with_stable_ties(): void
    {
        $operator = User::factory()->operator()->create();
        $expected = [];
        foreach ([WorkRequestStatus::AwaitingApproval, WorkRequestStatus::AwaitingReview] as $status) {
            $ids = [];
            foreach (range(1, 7) as $number) {
                $ids[] = WorkRequest::factory()->create([
                    'status' => $status, 'requested_at' => now()->startOfDay()->subDays(8 - min($number, 4)),
                    'late_entry_reason' => '정렬 검증', 'title' => $status->value.'-'.$number,
                ])->id;
            }
            $expected[$status->value] = array_slice($ids, 0, 5);
        }
        WorkRequest::factory()->create(['status' => WorkRequestStatus::Completed, 'title' => '대기 목록 제외 완료']);
        WorkRequest::factory()->create(['status' => WorkRequestStatus::Cancelled, 'title' => '대기 목록 제외 취소']);
        $response = $this->actingAs($operator)->get(route('dashboard'))->assertOk()
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['awaitingApprovalRequests'] === 7 && $metrics['awaitingReviewRequests'] === 7)
            ->assertDontSee('대기 목록 제외 완료')->assertDontSee('대기 목록 제외 취소');
        foreach (['awaitingApprovalRequests' => 'awaiting_approval', 'awaitingReviewRequests' => 'awaiting_review'] as $variable => $status) {
            $response->assertViewHas($variable, fn (Collection $requests): bool => $requests->modelKeys() === $expected[$status])
                ->assertSee(route('requests.index', ['status' => $status]), false)
                ->assertSee(route('requests.show', $expected[$status][0]), false)
                ->assertDontSee($status.'-6')->assertDontSee($status.'-7');
            $this->get(route('requests.index', ['status' => $status]))->assertOk()
                ->assertViewHas('workRequests', fn ($requests): bool => $requests->total() === 7 && $requests->every(fn (WorkRequest $request): bool => $request->status->value === $status));
        }
    }

    public function test_pending_empty_states_are_visible_to_both_system_roles(): void
    {
        foreach ([User::factory()->operator()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertSee('data-test="empty-awaiting_approval"', false)
                ->assertSee('data-test="empty-awaiting_review"', false)
                ->assertSee('전체 0건');
        }
    }

    public function test_pending_list_escapes_content_and_disappears_after_revision(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create(['status' => WorkRequestStatus::AwaitingApproval, 'title' => '<script>alert(1)</script>']);
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $this->actingAs($operator)->get(route('dashboard'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        (new TransitionWorkRequest)->handle($admin, $request, WorkRequestStatus::Estimating, '견적 수정');
        $this->get(route('dashboard'))->assertOk()->assertSee('data-test="empty-awaiting_approval"', false)
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['awaitingApprovalRequests'] === 0);
    }

    public function test_both_customer_roles_never_receive_global_pending_lists(): void
    {
        $foreign = WorkRequest::factory()->create(['status' => WorkRequestStatus::AwaitingReview, 'title' => '타사 검수 비공개']);
        foreach ([User::factory()->customerAdmin()->create(), User::factory()->customerUser()->create()] as $user) {
            WorkRequest::factory()->for($user->company)->create(['status' => WorkRequestStatus::AwaitingApproval, 'title' => '자사 승인 대기']);
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewIs('dashboard.customer')
                ->assertDontSee('타사 검수 비공개')->assertDontSee('data-test="pending-awaiting_review"', false)
                ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['awaitingApprovalRequests'] === 1 && $metrics['awaitingReviewRequests'] === 0);
            $this->get(route('requests.show', $foreign))->assertForbidden();
        }
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
