<?php

namespace Tests\Feature;

use App\Actions\ApproveEstimateVersion;
use App\Actions\CreateEstimateVersion;
use App\Enums\ServiceContractStatus;
use App\Enums\TimeLedgerType;
use App\Enums\UserRole;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateApproval;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class EstimateDecisionTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['User-Agent' => 'Test browser']);
    }

    public function test_admin_can_review_approve_and_retry_without_duplicate_reservation(): void
    {
        [$request, , $admin, , $estimate] = $this->timeFixture();
        $response = $this->actingAs($admin)->get(route('requests.estimates.decision', [$request, $estimate]))->assertOk()
            ->assertSee('60분')->assertSee('60,000원')->assertSee($estimate->included_scope)->assertSee($estimate->excluded_scope)
            ->assertSee($estimate->scheduled_on->format('Y.m.d'))->assertSee($estimate->usage_month->format('Y.m'))
            ->assertSee(ApproveEstimateVersion::APPROVAL_TEXT)->assertSee('수정 요청 보내기');
        $key = $response->viewData('idempotencyKey');
        $this->assertTrue(Str::isUuid($key));
        $this->assertSame(0, EstimateApproval::count());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeaders(['User-Agent' => 'Decision test browser']);
        $payload = $this->approvalPayload($key) + ['ip_address' => '198.51.100.9', 'user_agent' => 'FORGED', 'amount' => 1];
        $this->post(route('requests.estimates.approve', [$request, $estimate]), $payload)->assertRedirect(route('requests.show', $request));
        $approval = EstimateApproval::sole();
        $this->assertSame('203.0.113.7', $approval->ip_address);
        $this->assertSame('Decision test browser', $approval->user_agent);
        $this->assertSame(WorkRequestStatus::Queued, $request->fresh()->status);
        $this->assertSame(60, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->sole()->minutes);
        $activityCount = $request->activities()->count();
        $this->post(route('requests.estimates.approve', [$request, $estimate]), $payload)->assertRedirect();
        $this->assertSame(1, EstimateApproval::count());
        $this->assertSame(1, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(1, $request->statusChanges()->count());
        $this->assertSame($activityCount, $request->activities()->count());
        $this->get(route('requests.estimates.decision', [$request, $estimate]))->assertOk()->assertDontSee('name="confirmed"', false)->assertDontSee('name="reason"', false);
    }

    /** @return array<string, array{UserRole}> */
    public static function forbiddenRoles(): array
    {
        return ['customer user' => [UserRole::CustomerUser], 'operator' => [UserRole::Operator], 'super admin' => [UserRole::SuperAdmin]];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_only_customer_admin_may_use_decision_routes(UserRole $role): void
    {
        [$request, , , , $estimate] = $this->timeFixture();
        $viewer = User::factory()->create(['role' => $role, 'company_id' => $role->isSystemRole() ? null : $request->company_id]);
        $this->actingAs($viewer)->get(route('requests.show', $request))->assertOk()->assertDontSee('견적 확인·승인 또는 수정 요청 →');
        $this->get(route('requests.estimates.decision', [$request, $estimate]))->assertForbidden();
        $this->post(route('requests.estimates.approve', [$request, $estimate]), $this->approvalPayload())->assertForbidden();
        $this->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => '수정'])->assertForbidden();
        $this->assertSame(0, EstimateApproval::count());
    }

    public function test_guest_other_company_and_mismatched_request_are_rejected(): void
    {
        [$request, , $admin, , $estimate] = $this->timeFixture();
        $this->get(route('requests.estimates.decision', [$request, $estimate]))->assertRedirect(route('login'));
        $other = User::factory()->customerAdmin()->create();
        $this->actingAs($other)->get(route('requests.estimates.decision', [$request, $estimate]))->assertForbidden();
        $this->post(route('requests.estimates.approve', [$request, $estimate]), $this->approvalPayload())->assertForbidden();
        $this->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => '수정'])->assertForbidden();
        $sameCompanyRequest = WorkRequest::factory()->for($request->company)->create();
        $this->actingAs($admin)->get(route('requests.estimates.decision', [$sameCompanyRequest, $estimate]))->assertNotFound();
        $this->post(route('requests.estimates.approve', [$sameCompanyRequest, $estimate]), $this->approvalPayload())->assertNotFound();
        $this->post(route('requests.estimates.revision', [$sameCompanyRequest, $estimate]), ['reason' => '수정'])->assertNotFound();
    }

    public function test_drafts_and_stale_submitted_versions_cannot_be_decided(): void
    {
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        $draft = (new CreateEstimateVersion)->handle($operator, $request, $estimate->pricingAssessment, '새 초안 비밀', '제외', today()->addWeek(), today()->startOfMonth());
        $this->actingAs($admin)->get(route('requests.estimates.decision', [$request, $draft]))->assertForbidden();
        $this->get(route('requests.estimates.decision', [$request, $estimate]))->assertOk()->assertDontSee('name="confirmed"', false)->assertDontSee('새 초안 비밀');
        foreach ([$draft, $estimate] as $version) {
            $this->post(route('requests.estimates.approve', [$request, $version]), $this->approvalPayload())->assertSessionHasErrors('estimate');
            $this->post(route('requests.estimates.revision', [$request, $version]), ['reason' => '수정'])->assertSessionHasErrors('estimate');
        }
        $this->assertSame(0, EstimateApproval::count());
        $this->assertSame(0, $request->statusChanges()->count());
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidApprovalInputs(): array
    {
        return ['unchecked' => ['confirmed', 0], 'altered text' => ['approval_text', '다른 승인 문구'], 'invalid key' => ['idempotency_key', 'bad-key']];
    }

    #[DataProvider('invalidApprovalInputs')]
    public function test_invalid_confirmation_is_rejected(string $field, mixed $value): void
    {
        [$request, , $admin, , $estimate] = $this->timeFixture();
        $this->actingAs($admin)->post(route('requests.estimates.approve', [$request, $estimate]), array_replace($this->approvalPayload(), [$field => $value]))->assertSessionHasErrors($field);
        $this->assertSame(0, EstimateApproval::count());
        $this->assertSame(0, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
    }

    /** @return array<string, array{string}> */
    public static function approvalBlockers(): array
    {
        return ['insufficient time' => ['time'], 'invalid contract' => ['contract']];
    }

    #[DataProvider('approvalBlockers')]
    public function test_approval_failure_keeps_request_and_ledger_unchanged(string $case): void
    {
        [$request, , $admin, , $estimate] = $this->timeFixture($case === 'time' ? 59 : 100);
        if ($case === 'contract') {
            $request->serviceContract->update(['status' => ServiceContractStatus::Draft]);
        }
        $this->actingAs($admin)->withHeaders(['User-Agent' => 'Test browser'])
            ->post(route('requests.estimates.approve', [$request, $estimate]), $this->approvalPayload())->assertSessionHasErrors($case === 'time' ? 'minutes' : 'service_contract_id');
        $this->assertSame(0, EstimateApproval::count());
        $this->assertSame(0, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $request->fresh()->status);
        $this->assertNull($request->fresh()->approved_estimate_version_id);
    }

    public function test_revision_requires_reason_and_records_it_without_approval_or_reservation(): void
    {
        [$request, , $admin, , $estimate] = $this->timeFixture();
        $this->actingAs($admin)->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => ' '])->assertSessionHasErrors('reason');
        $reason = '<script>alert("revision")</script>';
        $this->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => $reason])->assertRedirect(route('requests.show', $request));
        $this->assertSame(WorkRequestStatus::Estimating, $request->fresh()->status);
        $change = $request->statusChanges()->sole();
        $this->assertSame($admin->id, $change->changed_by);
        $this->assertSame($estimate->id, $change->estimate_version_id);
        $this->assertSame($reason, $change->reason);
        $this->assertSame(0, EstimateApproval::count());
        $this->assertSame(0, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->get(route('requests.show', $request))->assertOk()->assertSee($reason)->assertDontSee($reason, false);
        $this->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => $reason])->assertSessionHasErrors('estimate');
        $this->post(route('requests.estimates.approve', [$request, $estimate]), $this->approvalPayload())->assertSessionHasErrors('estimate');
        $this->assertSame(1, $request->statusChanges()->count());
    }

    public function test_revision_after_approval_cannot_release_or_change_approved_work(): void
    {
        [$request, , $admin, , $estimate] = $this->timeFixture();
        $this->actingAs($admin)->withHeaders(['User-Agent' => 'Test browser'])->post(route('requests.estimates.approve', [$request, $estimate]), $this->approvalPayload())->assertRedirect();
        $this->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => '뒤늦은 수정'])->assertSessionHasErrors('estimate');
        $this->assertSame(WorkRequestStatus::Queued, $request->fresh()->status);
        $this->assertSame(1, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(1, $request->statusChanges()->count());
    }

    public function test_revision_history_failure_rolls_back_state(): void
    {
        [$request, , $admin, , $estimate] = $this->timeFixture();
        $activities = $request->activities()->count();
        WorkRequestStatusChange::creating(fn () => throw new RuntimeException('revision history failed'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => '범위 수정']);
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('revision history failed', $exception->getMessage());
            $this->assertSame(WorkRequestStatus::AwaitingApproval, $request->fresh()->status);
            $this->assertSame(0, $request->statusChanges()->count());
            $this->assertSame($activities, $request->activities()->count());
        } finally {
            WorkRequestStatusChange::flushEventListeners();
        }
    }

    public function test_revision_new_version_submission_and_approval_work_end_to_end(): void
    {
        [$request, $operator, $admin, , $first] = $this->timeFixture();
        $this->actingAs($admin)->post(route('requests.estimates.revision', [$request, $first]), ['reason' => '범위를 줄여 주세요.'])->assertRedirect();
        $this->actingAs($operator)->post(route('requests.estimates.store', $request), [
            'base_version' => 1, 'difficulty' => 'normal', 'estimated_minutes' => 30,
            'rationale' => '범위 축소 반영', 'included_scope' => '수정 포함 범위', 'excluded_scope' => '수정 제외 범위',
            'scheduled_on' => today()->addWeek()->toDateString(), 'usage_month' => today()->format('Y-m'),
        ])->assertRedirect();
        $second = $request->latestEstimateVersion()->firstOrFail();
        $this->assertSame(2, $second->version);
        $this->post(route('requests.estimates.submit', [$request, $second]), ['confirmed' => 1])->assertRedirect();
        $this->actingAs($admin)->get(route('requests.estimates.decision', [$request, $second]))->assertOk()->assertSee('30분')->assertSee('수정 포함 범위');
        $this->post(route('requests.estimates.approve', [$request, $first]), $this->approvalPayload())->assertSessionHasErrors('estimate');
        $this->post(route('requests.estimates.approve', [$request, $second]), $this->approvalPayload())->assertRedirect();
        $this->assertSame($second->id, $request->fresh()->approved_estimate_version_id);
        $this->assertSame(30, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->sole()->minutes);
        $this->assertSame(1, EstimateApproval::count());
        $this->assertSame(WorkRequestStatus::Queued, $request->fresh()->status);
        $this->assertSame(3, $request->statusChanges()->count());
    }

    public function test_deactivated_admin_cannot_submit_a_previously_opened_form(): void
    {
        [$request, , $admin, , $estimate] = $this->timeFixture();
        $this->actingAs($admin)->get(route('requests.estimates.decision', [$request, $estimate]))->assertOk();
        $admin->forceFill(['is_active' => false])->save();
        $this->post(route('requests.estimates.approve', [$request, $estimate]), $this->approvalPayload())->assertForbidden();
        $this->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => '수정'])->assertForbidden();
        $this->assertSame(0, EstimateApproval::count());
    }

    /** @return array<string, mixed> */
    private function approvalPayload(?string $key = null): array
    {
        return ['confirmed' => 1, 'approval_text' => ApproveEstimateVersion::APPROVAL_TEXT, 'idempotency_key' => $key ?? (string) Str::uuid()];
    }
}
