<?php

namespace Tests\Feature\Models;

use App\Actions\AssessRequestPricing;
use App\Enums\PricingDecision;
use App\Enums\WorkDifficulty;
use App\Models\PricingAssessment;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRules(): array
    {
        return [
            'zero rate' => [['hourly_rate' => 0]],
            'negative rate' => [['hourly_rate' => -1]],
            'rate limit' => [['hourly_rate' => 100000001]],
            'surcharge limit' => [['urgent_surcharge_bps' => 100001]],
            'empty criteria' => [['urgent_criteria' => '   ']],
            'invalid type' => [['work_type' => 'unknown']],
            'invalid difficulty' => [['difficulty' => 'unknown']],
            'reversed period' => [['valid_from' => '2026-10-03', 'valid_until' => '2026-10-02']],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('invalidRules')]
    public function test_database_rejects_invalid_price_rules(array $attributes): void
    {
        $rule = PricingRule::factory()->create();
        $this->expectException(QueryException::class);
        PricingRule::whereKey($rule->id)->update($attributes);
    }

    /** @return array<string, array{int, int, int, bool, int}> */
    public static function amounts(): array
    {
        return [
            'ordinary 90 minutes' => [60000, 90, 2500, false, 90000],
            'urgent 90 minutes' => [60000, 90, 2500, true, 112500],
            'fraction rounded once' => [1001, 1, 2500, true, 21],
            'one won rounded up' => [1, 1, 0, false, 1],
            'safe maximum' => [100000000, 10000000, 100000, true, 183333333333334],
        ];
    }

    #[DataProvider('amounts')]
    public function test_price_calculation_uses_integer_minutes_and_surcharge(int $rate, int $minutes, int $bps, bool $urgent, int $amount): void
    {
        PricingRule::factory()->create(['hourly_rate' => $rate, 'urgent_surcharge_bps' => $bps]);
        $request = WorkRequest::factory()->create(['is_urgent' => $urgent]);
        $assessment = $this->assess($request, $minutes);
        $this->assertSame($amount, $assessment->amount);
        $this->assertSame($urgent ? $bps : 0, $assessment->rate_snapshot['applied_surcharge_bps']);
        $this->assertSame($request->company_id, $assessment->company_id);
    }

    public function test_effective_dates_include_both_boundaries_and_select_the_matching_period(): void
    {
        $old = PricingRule::factory()->create(['valid_from' => '2026-10-01', 'valid_until' => '2026-10-03']);
        $new = PricingRule::factory()->create(['valid_from' => '2026-10-04', 'hourly_rate' => 120000]);
        $request = WorkRequest::factory()->create();
        foreach (['2026-10-01', '2026-10-03'] as $date) {
            $this->travelTo($date.' 12:00:00');
            $this->assertSame($old->id, $this->assess($request)->pricing_rule_id);
        }
        $this->travelTo('2026-10-04 12:00:00');
        $this->assertSame($new->id, $this->assess($request)->pricing_rule_id);
    }

    public function test_overlapping_price_rules_are_rejected_without_creating_an_assessment(): void
    {
        PricingRule::factory()->count(2)->create();
        try {
            $this->assess(WorkRequest::factory()->create());
            $this->fail('Overlapping rules were accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, PricingAssessment::count());
        }
    }

    public function test_missing_matching_rule_is_rejected(): void
    {
        PricingRule::factory()->create(['difficulty' => WorkDifficulty::High]);
        $this->expectException(ValidationException::class);
        $this->assess(WorkRequest::factory()->create());
    }

    public function test_snapshot_preserves_original_rate_criteria_and_rationale(): void
    {
        $rule = PricingRule::factory()->create();
        $assessment = $this->assess(WorkRequest::factory()->create());
        $snapshot = $assessment->rate_snapshot;
        $rule->update(['hourly_rate' => 120000, 'urgent_criteria' => '변경된 가산 기준']);
        $assessment->refresh();
        $storedSnapshot = $assessment->rate_snapshot;
        ksort($snapshot);
        ksort($storedSnapshot);
        $this->assertSame($snapshot, $storedSnapshot);
        $this->assertSame(60000, $assessment->amount);
        $this->assertSame('작업 범위와 난이도 검토', $assessment->rationale);
        $this->expectException(LogicException::class);
        $assessment->update(['amount' => 1]);
    }

    public function test_rejection_and_renegotiation_preserve_reason_without_changing_request_status(): void
    {
        PricingRule::factory()->create();
        $request = WorkRequest::factory()->create();
        $status = $request->status;
        foreach ([PricingDecision::Rejected, PricingDecision::Renegotiation] as $decision) {
            $assessment = (new AssessRequestPricing)->handle(User::factory()->operator()->create(), $request, WorkDifficulty::Normal, 60, today(), '일정과 예산 검토', $decision, '예산 또는 납기 조정 필요');
            $this->assertSame($decision, $assessment->decision);
            $this->assertSame('예산 또는 납기 조정 필요', $assessment->decision_reason);
        }
        $this->assertSame($status, $request->fresh()->status);
    }

    public function test_empty_renegotiation_reason_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new AssessRequestPricing)->handle(User::factory()->operator()->create(), WorkRequest::factory()->create(), WorkDifficulty::Normal, 60, today(), '검토', PricingDecision::Renegotiation, ' ');
    }

    public function test_database_also_requires_rejection_reason(): void
    {
        PricingRule::factory()->create();
        $assessment = $this->assess(WorkRequest::factory()->create());
        $this->expectException(QueryException::class);
        PricingAssessment::whereKey($assessment->id)->update(['decision' => 'rejected', 'decision_reason' => null]);
    }

    public function test_customer_cannot_create_or_read_internal_price_assessment(): void
    {
        PricingRule::factory()->create();
        $request = WorkRequest::factory()->create();
        $assessment = $this->assess($request);
        $customer = $request->submitter;
        $this->assertFalse(Gate::forUser($customer)->allows('view', $assessment));
        $this->expectException(AuthorizationException::class);
        (new AssessRequestPricing)->handle($customer, $request, WorkDifficulty::Normal, 60, today(), '검토');
    }

    public function test_inactive_operator_cannot_assess(): void
    {
        $this->expectException(AuthorizationException::class);
        (new AssessRequestPricing)->handle(User::factory()->operator()->inactive()->create(), WorkRequest::factory()->create(), WorkDifficulty::Normal, 60, today(), '검토');
    }

    public function test_database_rejects_cross_company_request_link(): void
    {
        PricingRule::factory()->create();
        $assessment = $this->assess(WorkRequest::factory()->create());
        $other = WorkRequest::factory()->create();
        $this->expectException(QueryException::class);
        PricingAssessment::whereKey($assessment->id)->update(['work_request_id' => $other->id]);
    }

    private function assess(WorkRequest $request, int $minutes = 60): PricingAssessment
    {
        return (new AssessRequestPricing)->handle(User::factory()->operator()->create(), $request, WorkDifficulty::Normal, $minutes, today(), '작업 범위와 난이도 검토');
    }
}
