<?php

namespace Tests\Feature;

use App\Actions\TransitionWorkRequest;
use App\Enums\UserRole;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateVersion;
use App\Models\PricingAssessment;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EstimateAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private WorkRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->operator()->create();
        $this->request = WorkRequest::factory()->create();
        PricingRule::factory()->create();
    }

    public function test_operator_can_create_preview_and_submit_a_server_priced_estimate(): void
    {
        $this->actingAs($this->operator)->get(route('requests.show', $this->request))->assertOk()->assertSee('견적 작성');
        $this->get(route('requests.estimates.create', $this->request))->assertOk()->assertSee('시간당 60,000원')->assertSee('초안 저장 후 미리보기');
        $this->post(route('requests.estimates.store', $this->request), $this->payload() + ['amount' => 1, 'company_id' => 9999])->assertRedirect();
        $estimate = EstimateVersion::sole();
        $this->assertSame(60000, $estimate->amount);
        $this->assertSame($this->request->company_id, $estimate->company_id);
        $this->assertNull($estimate->submitted_at);
        $this->assertSame(WorkRequestStatus::Estimating, $this->request->fresh()->status);
        PricingRule::query()->update(['hourly_rate' => 90000]);
        $this->get(route('requests.estimates.preview', [$this->request, $estimate]))->assertOk()
            ->assertSee('60,000원')->assertSee('포함범위 테스트')->assertSee('제외범위 테스트')->assertSee('고객 공개 근거')->assertSee('견적 제출');
        $this->post(route('requests.estimates.submit', [$this->request, $estimate]), ['confirmed' => 1])->assertRedirect(route('requests.show', $this->request));
        $this->assertNotNull($estimate->fresh()->submitted_at);
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $this->request->fresh()->status);
        $this->assertSame(60000, $estimate->fresh()->amount);
        $this->assertSame(2, $this->request->statusChanges()->count());
        $this->post(route('requests.estimates.submit', [$this->request, $estimate]), ['confirmed' => 1])->assertRedirect();
        $this->assertSame(2, $this->request->statusChanges()->count());
        $this->get(route('requests.estimates.preview', [$this->request, $estimate]))->assertOk()->assertSee('제출 완료 · 수정 불가')->assertDontSee('name="confirmed"', false);
    }

    /** @return array<string, array{UserRole}> */
    public static function customers(): array
    {
        return ['admin' => [UserRole::CustomerAdmin], 'user' => [UserRole::CustomerUser]];
    }

    #[DataProvider('customers')]
    public function test_customers_cannot_access_any_authoring_route(UserRole $role): void
    {
        $estimate = $this->draft();
        $customer = User::factory()->for($this->request->company)->create(['role' => $role]);
        $this->actingAs($customer)->get(route('requests.show', $this->request))->assertOk()->assertDontSee('견적 작성 →');
        $this->get(route('requests.estimates.create', $this->request))->assertForbidden();
        $this->post(route('requests.estimates.store', $this->request), $this->payload())->assertForbidden();
        $this->get(route('requests.estimates.preview', [$this->request, $estimate]))->assertForbidden();
        $this->post(route('requests.estimates.submit', [$this->request, $estimate]), ['confirmed' => 1])->assertForbidden();
        $this->assertNull($estimate->fresh()->submitted_at);
    }

    public function test_guest_is_redirected_and_mismatched_nested_estimate_is_not_found(): void
    {
        $this->get(route('requests.estimates.create', $this->request))->assertRedirect(route('login'));
        $estimate = $this->draft();
        $other = WorkRequest::factory()->create();
        $this->get(route('requests.estimates.preview', [$other, $estimate]))->assertNotFound();
        $this->post(route('requests.estimates.submit', [$other, $estimate]), ['confirmed' => 1])->assertNotFound();
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidInputs(): array
    {
        return ['zero time' => ['estimated_minutes', 0], 'fractional time' => ['estimated_minutes', 1.5],
            'missing scope' => ['included_scope', ' '], 'missing exclusions' => ['excluded_scope', ''],
            'invalid difficulty' => ['difficulty', 'invalid'], 'invalid month' => ['usage_month', '2026-13'],
            'invalid date' => ['scheduled_on', '2026-02-30'], 'unsupported year' => ['usage_month', '0000-01']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_creates_no_partial_records(string $field, mixed $value): void
    {
        $this->actingAs($this->operator)->post(route('requests.estimates.store', $this->request), array_replace($this->payload(), [$field => $value]))->assertSessionHasErrors($field);
        $this->assertSame(0, EstimateVersion::count());
        $this->assertSame(0, PricingAssessment::count());
        $this->assertSame(WorkRequestStatus::Received, $this->request->fresh()->status);
    }

    public function test_missing_or_duplicate_price_rule_prevents_draft_creation(): void
    {
        PricingRule::query()->delete();
        $this->actingAs($this->operator)->get(route('requests.estimates.create', $this->request))->assertOk()->assertSee('적용 가능한 가격 기준이 없습니다.');
        $this->post(route('requests.estimates.store', $this->request), $this->payload())->assertSessionHasErrors('pricing_rule');
        PricingRule::factory()->count(2)->create();
        $this->post(route('requests.estimates.store', $this->request), $this->payload())->assertSessionHasErrors('pricing_rule');
        $this->assertSame(0, PricingAssessment::count());
        $this->assertSame(0, EstimateVersion::count());
    }

    public function test_stale_form_and_non_latest_draft_cannot_overwrite_or_submit(): void
    {
        $first = $this->draft();
        $this->post(route('requests.estimates.store', $this->request), $this->payload())->assertSessionHasErrors('base_version');
        $this->assertSame(1, EstimateVersion::count());
        $this->post(route('requests.estimates.store', $this->request), array_replace($this->payload(), ['base_version' => 1]))->assertRedirect();
        $this->post(route('requests.estimates.submit', [$this->request, $first]), ['confirmed' => 1])->assertSessionHasErrors('estimate');
        $this->assertNull($first->fresh()->submitted_at);
        $this->assertSame(WorkRequestStatus::Estimating, $this->request->fresh()->status);
        $this->get(route('requests.estimates.preview', [$this->request, $first]))->assertOk()->assertDontSee('name="confirmed"', false);
    }

    public function test_confirmation_and_writable_request_state_are_required(): void
    {
        $estimate = $this->draft();
        $this->post(route('requests.estimates.submit', [$this->request, $estimate]), [])->assertSessionHasErrors('confirmed');
        (new TransitionWorkRequest)->handle($this->operator, $this->request, WorkRequestStatus::Cancelled, '취소');
        $this->post(route('requests.estimates.submit', [$this->request, $estimate]), ['confirmed' => 1])->assertSessionHasErrors('estimate');
        $this->post(route('requests.estimates.store', $this->request), array_replace($this->payload(), ['base_version' => 1]))->assertSessionHasErrors('estimate');
        $this->assertNull($estimate->fresh()->submitted_at);
    }

    public function test_failed_status_history_rolls_back_submission(): void
    {
        $estimate = $this->draft();
        $count = $this->request->activities()->count();
        WorkRequestStatusChange::creating(fn () => throw new RuntimeException('history failed'));
        $this->withoutExceptionHandling();
        try {
            $this->post(route('requests.estimates.submit', [$this->request, $estimate]), ['confirmed' => 1]);
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('history failed', $exception->getMessage());
            $this->assertNull($estimate->fresh()->submitted_at);
            $this->assertSame(WorkRequestStatus::Estimating, $this->request->fresh()->status);
            $this->assertSame(1, $this->request->statusChanges()->count());
            $this->assertSame($count, $this->request->activities()->count());
        } finally {
            WorkRequestStatusChange::flushEventListeners();
        }
    }

    public function test_failed_draft_creation_rolls_back_assessment_and_request_state(): void
    {
        EstimateVersion::creating(fn () => throw new RuntimeException('draft failed'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->operator)->post(route('requests.estimates.store', $this->request), $this->payload());
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('draft failed', $exception->getMessage());
            $this->assertSame(0, PricingAssessment::count());
            $this->assertSame(0, EstimateVersion::count());
            $this->assertSame(WorkRequestStatus::Received, $this->request->fresh()->status);
        } finally {
            EstimateVersion::flushEventListeners();
        }
    }

    public function test_urgent_pricing_and_untrusted_preview_text(): void
    {
        $this->request->update(['is_urgent' => true]);
        $payload = '<script>alert("estimate")</script>';
        $this->actingAs($this->operator)->post(route('requests.estimates.store', $this->request),
            array_replace($this->payload(), ['included_scope' => $payload]))->assertRedirect();
        $estimate = EstimateVersion::sole();
        $this->assertSame(75000, $estimate->amount);
        $this->get(route('requests.estimates.preview', [$this->request, $estimate]))->assertOk()
            ->assertSee('75,000원')->assertSee('적용 할증 25.00%')->assertSee($payload)->assertDontSee($payload, false);
    }

    private function draft(): EstimateVersion
    {
        $this->actingAs($this->operator)->post(route('requests.estimates.store', $this->request), $this->payload())->assertRedirect();

        return EstimateVersion::sole();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['difficulty' => 'normal', 'estimated_minutes' => 60, 'rationale' => '고객 공개 근거',
            'included_scope' => '포함범위 테스트', 'excluded_scope' => '제외범위 테스트',
            'scheduled_on' => today()->addWeek()->toDateString(), 'usage_month' => today()->format('Y-m'), 'base_version' => 0];
    }
}
