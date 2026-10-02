<?php

namespace Tests\Feature;

use App\Enums\IntakeChannel;
use App\Enums\WorkRequestActivityType;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkRequestManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_request_create_show_and_comment_routes(): void
    {
        $workRequest = WorkRequest::factory()->create();

        $this->get('/requests/create')->assertRedirect(route('login'));
        $this->get("/requests/{$workRequest->id}")->assertRedirect(route('login'));
        $this->post("/requests/{$workRequest->id}/comments", ['body' => '확인 부탁드립니다.'])
            ->assertRedirect(route('login'));
    }

    public function test_create_form_scopes_projects_for_customers_and_exposes_manual_intake_to_operators(): void
    {
        $company = Company::factory()->create(['name' => '내 고객사']);
        $otherCompany = Company::factory()->create(['name' => '다른 고객사']);
        $customer = User::factory()->customerUser()->for($company)->create();
        $operator = User::factory()->operator()->create();
        $ownProject = Project::factory()->for($company)->create(['name' => '내 프로젝트']);
        $otherProject = Project::factory()->for($otherCompany)->create(['name' => '다른 프로젝트']);

        $this->actingAs($customer)
            ->get(route('requests.create'))
            ->assertOk()
            ->assertSee($ownProject->name)
            ->assertDontSee($otherProject->name)
            ->assertDontSee('name="company_id"', false)
            ->assertDontSee('name="intake_channel"', false);

        $this->actingAs($operator)
            ->get(route('requests.create'))
            ->assertOk()
            ->assertSee($company->name)
            ->assertSee($otherCompany->name)
            ->assertSee($ownProject->name)
            ->assertSee($otherProject->name)
            ->assertSee('name="company_id"', false)
            ->assertSee('name="intake_channel"', false)
            ->assertSee('name="source_reference"', false);
    }

    public function test_customer_can_create_a_web_request_for_an_own_company_project(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $project = Project::factory()->for($company)->create();

        $response = $this->actingAs($customer)->post(route('requests.store'), [
            'company_id' => $otherCompany->id,
            'project_id' => $project->id,
            'title' => '결제 완료 화면 개선',
            'requirements' => "결제 완료 후 주문 번호를 크게 표시해 주세요.\n모바일에서도 확인 가능해야 합니다.",
            'type' => WorkRequestType::Feature->value,
            'priority' => WorkRequestPriority::High->value,
            'is_urgent' => '1',
            'desired_due_date' => now()->addWeek()->toDateString(),
            'intake_channel' => IntakeChannel::Phone->value,
        ]);

        $workRequest = WorkRequest::query()->where('title', '결제 완료 화면 개선')->firstOrFail();

        $response->assertRedirect(route('requests.show', $workRequest));
        $this->assertSame($company->id, $workRequest->company_id);
        $this->assertSame($customer->id, $workRequest->submitted_by);
        $this->assertSame(IntakeChannel::Web, $workRequest->intake_channel);
        $this->assertSame(WorkRequestStatus::Received, $workRequest->status);
        $this->assertTrue($workRequest->is_urgent);
        $this->assertDatabaseHas('work_request_activities', [
            'work_request_id' => $workRequest->id,
            'actor_id' => $customer->id,
            'type' => WorkRequestActivityType::RequestCreated->value,
        ]);
    }

    public function test_customer_cannot_create_a_request_for_another_company_project(): void
    {
        $company = Company::factory()->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $otherProject = Project::factory()->create();

        $this->actingAs($customer)
            ->from(route('requests.create'))
            ->post(route('requests.store'), $this->validPayload($otherProject, [
                'title' => '교차 고객사 요청',
            ]))
            ->assertRedirect(route('requests.create'))
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseMissing('work_requests', ['title' => '교차 고객사 요청']);
    }

    public function test_operator_can_preserve_manual_intake_details_and_original_request_time(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(14, 30));

        $operator = User::factory()->operator()->create();
        $company = Company::factory()->create();
        $project = Project::factory()->for($company)->create();

        $response = $this->actingAs($operator)->post(route('requests.store'), $this->validPayload($project, [
            'company_id' => $company->id,
            'title' => '전화 접수 요청',
            'intake_channel' => IntakeChannel::Phone->value,
            'source_reference' => '고객 담당자 010-0000-0000 통화',
            'intake_summary' => '주문 내역 다운로드 기능 요청',
            'requested_at' => '2026-10-01T09:20',
            'late_entry_reason' => '담당자 외근으로 다음 날 등록',
        ]));

        $workRequest = WorkRequest::query()->where('title', '전화 접수 요청')->firstOrFail();

        $response->assertRedirect(route('requests.show', $workRequest));
        $this->assertSame(IntakeChannel::Phone, $workRequest->intake_channel);
        $this->assertSame('2026-10-01 09:20:00', $workRequest->requested_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 14:30:00', $workRequest->registered_at->format('Y-m-d H:i:s'));
        $this->assertSame('담당자 외근으로 다음 날 등록', $workRequest->late_entry_reason);
    }

    public function test_manual_intake_requires_source_summary_and_late_reason(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(14, 30));

        $operator = User::factory()->operator()->create();
        $company = Company::factory()->create();
        $project = Project::factory()->for($company)->create();

        $this->actingAs($operator)
            ->from(route('requests.create'))
            ->post(route('requests.store'), $this->validPayload($project, [
                'company_id' => $company->id,
                'intake_channel' => IntakeChannel::Email->value,
                'source_reference' => '',
                'intake_summary' => '',
                'requested_at' => '2026-09-30T10:00',
                'late_entry_reason' => '',
            ]))
            ->assertRedirect(route('requests.create'))
            ->assertSessionHasErrors(['source_reference', 'intake_summary', 'late_entry_reason']);
    }

    public function test_detail_displays_requirements_comments_and_basic_activity_history(): void
    {
        $company = Company::factory()->create(['name' => '상세 고객사']);
        $customer = User::factory()->customerUser()->for($company)->create(['name' => '요청 담당자']);
        $project = Project::factory()->for($company)->create(['name' => '상세 프로젝트']);
        $workRequest = WorkRequest::factory()->for($company)->for($project)->create([
            'submitted_by' => $customer->id,
            'title' => '상세 화면 요청',
            'requirements' => '요구사항 전문이 이곳에 표시됩니다.',
        ]);
        WorkRequestComment::factory()->for($company)->for($workRequest)->create([
            'author_id' => $customer->id,
            'body' => '댓글 내용도 함께 표시됩니다.',
        ]);

        $this->actingAs($customer)
            ->get(route('requests.show', $workRequest))
            ->assertOk()
            ->assertSee('상세 화면 요청')
            ->assertSee('요구사항 전문이 이곳에 표시됩니다.')
            ->assertSee('댓글 내용도 함께 표시됩니다.')
            ->assertSee('요청이 등록되었습니다.')
            ->assertSee('댓글이 등록되었습니다.')
            ->assertSee('data-test="request-comments"', false)
            ->assertSee('data-test="request-activities"', false);
    }

    public function test_customer_cannot_view_or_comment_on_another_company_request(): void
    {
        $customer = User::factory()->customerUser()->create();
        $otherRequest = WorkRequest::factory()->create();

        $this->actingAs($customer)
            ->get(route('requests.show', $otherRequest))
            ->assertForbidden();

        $this->actingAs($customer)
            ->post(route('requests.comments.store', $otherRequest), ['body' => '볼 수 없는 요청'])
            ->assertForbidden();
    }

    public function test_customer_can_add_a_comment_and_comment_validation_is_enforced(): void
    {
        $company = Company::factory()->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        $workRequest = WorkRequest::factory()->for($company)->create();

        $this->actingAs($customer)
            ->post(route('requests.comments.store', $workRequest), ['body' => '작업 전 확인할 내용을 남깁니다.'])
            ->assertRedirect(route('requests.show', $workRequest).'#comments');

        $comment = WorkRequestComment::query()->where('body', '작업 전 확인할 내용을 남깁니다.')->firstOrFail();
        $this->assertSame($company->id, $comment->company_id);
        $this->assertSame($customer->id, $comment->author_id);
        $this->assertDatabaseHas('work_request_activities', [
            'work_request_id' => $workRequest->id,
            'comment_id' => $comment->id,
            'type' => WorkRequestActivityType::CommentCreated->value,
        ]);

        $this->actingAs($customer)
            ->from(route('requests.show', $workRequest))
            ->post(route('requests.comments.store', $workRequest), ['body' => ''])
            ->assertSessionHasErrors('body');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $project->id,
            'title' => '새 작업 요청',
            'requirements' => '구현할 요구사항을 충분히 설명합니다.',
            'type' => WorkRequestType::Feature->value,
            'priority' => WorkRequestPriority::Normal->value,
            'is_urgent' => '0',
            'desired_due_date' => null,
            'intake_channel' => IntakeChannel::Web->value,
            'source_reference' => null,
            'intake_summary' => null,
            'requested_at' => null,
            'late_entry_reason' => null,
        ], $overrides);
    }
}
