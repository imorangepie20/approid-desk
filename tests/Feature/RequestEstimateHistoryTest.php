<?php

namespace Tests\Feature;

use App\Actions\ApproveEstimateVersion;
use App\Actions\AssessRequestPricing;
use App\Actions\CreateEstimateVersion;
use App\Actions\TransitionWorkRequest;
use App\Enums\ServiceContractStatus;
use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateApproval;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class RequestEstimateHistoryTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    public function test_customers_see_submitted_versions_but_not_drafts_or_internal_assessments(): void
    {
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, 60, today(), '비공개 분석 고유문구');
        $draft = (new CreateEstimateVersion)->handle($operator, $request, $assessment, '비공개 초안 고유문구', '비공개 제외', today()->addWeek(), today()->startOfMonth());
        $customer = User::factory()->customerUser()->for($request->company)->create();
        foreach ([$admin, $customer] as $viewer) {
            $this->actingAs($viewer)->get(route('requests.show', $request))->assertOk()
                ->assertSee('history-estimate-'.$estimate->id)->assertSee('60분')->assertSee('60,000원')
                ->assertSee($estimate->scheduled_on->format('Y.m.d'))->assertSee($estimate->usage_month->format('Y.m'))
                ->assertSee($estimate->included_scope)->assertSee($estimate->excluded_scope)->assertSee($estimate->pricing_rationale)
                ->assertDontSee('history-estimate-'.$draft->id)->assertDontSee('비공개 초안 고유문구')
                ->assertDontSee('비공개 분석 고유문구')->assertDontSee('history-assessment-');
        }
        $this->actingAs($operator)->get(route('requests.show', $request))->assertOk()
            ->assertSee('history-estimate-'.$draft->id)->assertSee('비공개 초안 고유문구')
            ->assertSee('비공개 분석 고유문구')->assertSee('초안 · 내부 전용')->assertSee('가격 분석 · 견적 가능');
    }

    public function test_approval_and_status_history_are_newest_first_without_sensitive_evidence(): void
    {
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        $this->travel(1)->minutes();
        $key = (string) Str::uuid();
        (new ApproveEstimateVersion)->handle($admin, $estimate, $key, ApproveEstimateVersion::APPROVAL_TEXT, '203.0.113.99', 'PRIVATE-USER-AGENT');
        $this->travel(1)->minutes();
        $change = (new TransitionWorkRequest)->handle($operator, $request, WorkRequestStatus::OnHold, '고객 자료 대기 사유');
        $response = $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()
            ->assertSeeInOrder(['history-status-'.$change->id, 'history-approval-'.$estimate->id, 'history-estimate-'.$estimate->id])
            ->assertSee('승인된 버전')->assertSee('고객 승인 · 견적 v1')->assertSee($admin->name)
            ->assertSee(ApproveEstimateVersion::APPROVAL_TEXT)->assertSee('고객 자료 대기 사유')
            ->assertSee('계약 서명 확인')->assertSee('서명 확인 완료')
            ->assertDontSee('203.0.113.99')->assertDontSee('PRIVATE-USER-AGENT')->assertDontSee($key)
            ->assertDontSee($request->serviceContract->document_path);
        $events = $response->viewData('estimateHistory');
        $approvalEvent = collect($events)->firstWhere('kind', 'approval');
        $attributes = $approvalEvent['record']->approval->getAttributes();
        foreach (['ip_address', 'user_agent', 'idempotency_key'] as $field) {
            $this->assertArrayNotHasKey($field, $attributes);
        }
    }

    public function test_history_never_includes_another_request_and_other_company_is_forbidden(): void
    {
        [$request, $operator, $admin] = $this->timeFixture();
        $otherRequest = WorkRequest::factory()->for($request->company)->create();
        $otherEstimate = $this->timeEstimate($operator, $otherRequest);
        $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()->assertDontSee('history-estimate-'.$otherEstimate->id);
        $outsider = User::factory()->customerAdmin()->create();
        $this->actingAs($outsider)->get(route('requests.show', $request))->assertForbidden();
    }

    public function test_request_without_contract_or_estimate_displays_empty_state(): void
    {
        $request = WorkRequest::factory()->create();
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()
            ->assertSee('연결된 계약이 없습니다.')->assertSee('표시할 견적·승인 이력이 없습니다.')
            ->assertSee('request-comments')->assertSee('request-activities');
    }

    public function test_expired_contract_does_not_appear_to_be_currently_eligible(): void
    {
        [$request, , $admin] = $this->timeFixture();
        $request->serviceContract->update(['status' => ServiceContractStatus::Expired]);
        $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()
            ->assertSee('만료')->assertSee('서명 확인 완료')->assertSee('현재 계약 조건 미충족');
    }

    public function test_unconfirmed_contract_is_not_shown_as_a_signature_event(): void
    {
        $contract = ServiceContract::factory()->create();
        $request = WorkRequest::factory()->create(['company_id' => $contract->company_id, 'service_contract_id' => $contract->id]);
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()
            ->assertSee('서명 미확인')->assertSee('현재 계약 조건 미충족')->assertDontSee('history-contract-');
    }

    public function test_old_submitted_versions_remain_visible_and_get_does_not_mutate_history(): void
    {
        [$request, $operator, $admin, , $first] = $this->timeFixture();
        $this->travel(1)->minutes();
        $second = $this->timeEstimate($operator, $request, 30);
        $activities = $request->activities()->count();
        $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()
            ->assertSeeInOrder(['history-estimate-'.$second->id, 'history-estimate-'.$first->id])
            ->assertSee('견적 v1')->assertSee('견적 v2');
        $this->assertSame($activities, $request->activities()->count());
        $this->assertSame(0, EstimateApproval::count());
        $this->assertSame(0, $request->statusChanges()->count());
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $request->fresh()->status);
    }

    public function test_untrusted_estimate_and_status_text_is_escaped(): void
    {
        [$request, $operator] = $this->timeFixture();
        $payload = '<script>alert("history")</script>';
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, 60, today(), $payload);
        (new CreateEstimateVersion)->handle($operator, $request, $assessment, $payload, $payload, today()->addWeek(), today()->startOfMonth());
        (new TransitionWorkRequest)->handle($operator, $request, WorkRequestStatus::Cancelled, $payload);
        $this->actingAs($operator)->get(route('requests.show', $request))->assertOk()
            ->assertSee($payload)->assertDontSee($payload, false);
    }
}
