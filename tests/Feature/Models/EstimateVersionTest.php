<?php

namespace Tests\Feature\Models;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateEstimateVersion;
use App\Actions\SubmitEstimateVersion;
use App\Enums\PricingDecision;
use App\Enums\WorkDifficulty;
use App\Models\EstimateVersion;
use App\Models\PricingAssessment;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EstimateVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->operator()->create();
        PricingRule::factory()->create();
    }

    public function test_versions_copy_required_content_and_keep_latest_separate_from_approved(): void
    {
        $request = WorkRequest::factory()->create();
        $first = $this->createVersion($request);
        $this->assertSame(1, $first->version);
        $this->assertSame('검색 기능 개발', $first->included_scope);
        $this->assertSame('디자인 전면 개편 제외', $first->excluded_scope);
        $this->assertSame(90, $first->estimated_minutes);
        $this->assertSame(90000, $first->amount);
        $this->assertSame($this->operator->id, $first->created_by);
        $this->assertSame(today()->addWeek()->toDateString(), $first->scheduled_on->toDateString());
        $this->assertSame(today()->startOfMonth()->toDateString(), $first->usage_month->toDateString());
        $this->assertNotNull($first->created_at);
        $this->assertNull($request->approvedEstimateVersion()->first());

        (new SubmitEstimateVersion)->handle($this->operator, $first);
        // The actual approval action and its authorization are implemented in 2.4.
        $request->forceFill(['approved_estimate_version_id' => $first->id])->save();
        $second = $this->createVersion($request);
        $this->assertSame(2, $second->version);
        $this->assertSame($second->id, $request->latestEstimateVersion()->firstOrFail()->id);
        $this->assertSame($first->id, $request->approvedEstimateVersion()->firstOrFail()->id);
        $this->assertSame(2, $request->estimateVersions()->count());
        $this->assertSame(1, $this->createVersion(WorkRequest::factory()->create())->version);
    }

    public function test_old_price_is_reproducible_after_rate_changes(): void
    {
        $request = WorkRequest::factory()->urgent()->create();
        $estimate = $this->createVersion($request);
        PricingRule::query()->update(['hourly_rate' => 120000, 'urgent_surcharge_bps' => 5000]);
        $estimate->refresh();
        $snapshot = $estimate->rate_snapshot;
        $numerator = $snapshot['hourly_rate'] * $estimate->estimated_minutes * (10000 + $snapshot['applied_surcharge_bps']);
        $this->assertSame(112500, $estimate->amount);
        $this->assertSame($estimate->amount, intdiv($numerator + 599999, 600000));
        $this->assertSame('기능 범위와 예상시간을 검토한 판단', $estimate->pricing_rationale);
        $this->assertSame(270000, $this->createVersion($request)->amount);
    }

    /** @return array<string, array{string}> */
    public static function mutations(): array
    {
        return ['amount' => ['amount'], 'scope' => ['included_scope'], 'submission reset' => ['submitted_at'], 'delete' => ['delete']];
    }

    #[DataProvider('mutations')]
    public function test_database_blocks_mutating_submitted_estimates(string $field): void
    {
        $estimate = (new SubmitEstimateVersion)->handle($this->operator, $this->createVersion(WorkRequest::factory()->create()));
        $this->expectException(QueryException::class);
        if ($field === 'delete') {
            EstimateVersion::whereKey($estimate->id)->delete();
        } else {
            EstimateVersion::whereKey($estimate->id)->update([$field => match ($field) {
                'amount' => 1,
                'included_scope' => '변조',
                default => null,
            }]);
        }
    }

    public function test_submission_is_idempotent_and_older_draft_cannot_be_submitted(): void
    {
        $request = WorkRequest::factory()->create();
        $old = $this->createVersion($request);
        $latest = $this->createVersion($request);
        $action = new SubmitEstimateVersion;
        $submitted = $action->handle($this->operator, $latest);
        $this->travel(1)->hours();
        $this->assertTrue($submitted->submitted_at->equalTo($action->handle($this->operator, $latest)->submitted_at));
        $this->expectException(ValidationException::class);
        $action->handle($this->operator, $old);
    }

    public function test_customer_sees_only_submitted_own_company_versions_and_cannot_submit(): void
    {
        $request = WorkRequest::factory()->create();
        $customer = $request->submitter;
        $draft = $this->createVersion($request);
        $this->assertFalse(Gate::forUser($customer)->allows('view', $draft));
        $this->assertSame(0, EstimateVersion::visibleTo($customer)->count());
        $submitted = (new SubmitEstimateVersion)->handle($this->operator, $draft);
        $other = (new SubmitEstimateVersion)->handle($this->operator, $this->createVersion(WorkRequest::factory()->create()));
        $this->assertTrue(Gate::forUser($customer)->allows('view', $submitted));
        $this->assertFalse(Gate::forUser($customer)->allows('view', $other));
        $this->assertSame([$draft->id], EstimateVersion::visibleTo($customer)->pluck('id')->all());
        $this->expectException(AuthorizationException::class);
        (new SubmitEstimateVersion)->handle($customer, $submitted);
    }

    public function test_customer_and_inactive_operator_cannot_create_versions(): void
    {
        $request = WorkRequest::factory()->create();
        foreach ([$request->submitter, User::factory()->operator()->inactive()->create()] as $actor) {
            $this->assertFalse(Gate::forUser($actor)->allows('create', [EstimateVersion::class, $request]));
        }
        $assessment = $this->assess($request);
        $this->expectException(AuthorizationException::class);
        (new CreateEstimateVersion)->handle($request->submitter, $request, $assessment, '포함', '제외', today(), today()->startOfMonth());
    }

    public function test_same_company_other_request_assessment_is_rejected(): void
    {
        $request = WorkRequest::factory()->create();
        $other = WorkRequest::factory()->for($request->company)->create();
        $this->expectException(ValidationException::class);
        $this->createVersion($request, $this->assess($other));
    }

    public function test_non_feasible_assessment_cannot_become_an_estimate(): void
    {
        $request = WorkRequest::factory()->create();
        $assessment = (new AssessRequestPricing)->handle($this->operator, $request, WorkDifficulty::Normal, 90, today(), '판단', PricingDecision::Renegotiation, '예산 부족');
        $this->expectException(ValidationException::class);
        $this->createVersion($request, $assessment);
    }

    /** @return array<string, array{string, string, int}> */
    public static function invalidInput(): array
    {
        return ['blank included' => [' ', '제외', 1], 'blank excluded' => ['포함', ' ', 1], 'invalid month' => ['포함', '제외', 2]];
    }

    #[DataProvider('invalidInput')]
    public function test_required_scope_and_usage_month_are_validated(string $included, string $excluded, int $day): void
    {
        $request = WorkRequest::factory()->create();
        $assessment = $this->assess($request);
        $this->expectException(ValidationException::class);
        (new CreateEstimateVersion)->handle($this->operator, $request, $assessment, $included, $excluded, today(), today()->startOfMonth()->day($day));
    }

    public function test_database_prevents_duplicate_version_numbers(): void
    {
        $request = WorkRequest::factory()->create();
        $this->createVersion($request);
        $second = $this->createVersion($request);
        $this->expectException(QueryException::class);
        EstimateVersion::whereKey($second->id)->update(['version' => 1]);
    }

    public function test_database_prevents_cross_request_approved_pointer(): void
    {
        $request = WorkRequest::factory()->create();
        $other = $this->createVersion(WorkRequest::factory()->for($request->company)->create());
        $this->expectException(QueryException::class);
        WorkRequest::whereKey($request->id)->update(['approved_estimate_version_id' => $other->id]);
    }

    public function test_database_prevents_cross_request_assessment_link(): void
    {
        $request = WorkRequest::factory()->create();
        $estimate = $this->createVersion($request);
        $other = $this->assess(WorkRequest::factory()->for($request->company)->create());
        $this->expectException(QueryException::class);
        EstimateVersion::whereKey($estimate->id)->update(['pricing_assessment_id' => $other->id]);
    }

    private function assess(WorkRequest $request): PricingAssessment
    {
        return (new AssessRequestPricing)->handle($this->operator, $request, WorkDifficulty::Normal, 90, today(), '기능 범위와 예상시간을 검토한 판단');
    }

    private function createVersion(WorkRequest $request, ?PricingAssessment $assessment = null): EstimateVersion
    {
        return (new CreateEstimateVersion)->handle($this->operator, $request, $assessment ?? $this->assess($request), '검색 기능 개발', '디자인 전면 개편 제외', today()->addWeek(), today()->startOfMonth());
    }
}
