<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\PricingDecision;
use App\Enums\WorkDifficulty;
use App\Models\PricingAssessment;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Services\PricingCalculator;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssessRequestPricing
{
    public function handle(
        User $actor,
        WorkRequest $request,
        WorkDifficulty $difficulty,
        int $minutes,
        CarbonInterface $pricedOn,
        string $rationale,
        PricingDecision $decision = PricingDecision::Feasible,
        ?string $decisionReason = null,
    ): PricingAssessment {
        if (! $actor->canAccessWorkspace() || ! $actor->role->hasPermission(Permission::CreateEstimates)) {
            throw new AuthorizationException;
        }

        if ($minutes < 1 || $minutes > 10000000 || trim($rationale) === ''
            || ($decision !== PricingDecision::Feasible && trim($decisionReason ?? '') === '')) {
            throw ValidationException::withMessages(['pricing' => '예상 분, 가격 판단 근거와 거절·재협의 사유를 확인해 주세요.']);
        }

        return DB::transaction(function () use ($actor, $request, $difficulty, $minutes, $pricedOn, $rationale, $decision, $decisionReason): PricingAssessment {
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($actor)->authorize('view', $request);
            $date = $pricedOn->toDateString();
            $rules = PricingRule::query()
                ->where('work_type', $request->type->value)
                ->where('difficulty', $difficulty->value)
                ->whereDate('valid_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
                ->lockForUpdate()->get();

            if ($rules->count() !== 1) {
                throw ValidationException::withMessages(['pricing_rule' => '해당 날짜에 적용되는 가격 기준이 없거나 중복됩니다.']);
            }

            $rule = $rules->firstOrFail();
            $bps = $request->is_urgent ? $rule->urgent_surcharge_bps : 0;
            $amount = (new PricingCalculator)->calculate(
                $rule->hourly_rate,
                $minutes,
                $rule->urgent_surcharge_bps,
                $request->is_urgent,
            );

            return PricingAssessment::query()->create([
                'company_id' => $request->company_id,
                'work_request_id' => $request->id,
                'pricing_rule_id' => $rule->id,
                'assessed_by' => $actor->id,
                'priced_on' => $date,
                'estimated_minutes' => $minutes,
                'is_urgent' => $request->is_urgent,
                'amount' => $amount,
                'rate_snapshot' => [
                    'work_type' => $rule->work_type->value,
                    'difficulty' => $rule->difficulty->value,
                    'hourly_rate' => $rule->hourly_rate,
                    'urgent_surcharge_bps' => $rule->urgent_surcharge_bps,
                    'applied_surcharge_bps' => $bps,
                    'urgent_criteria' => $rule->urgent_criteria,
                    'valid_from' => $rule->valid_from->toDateString(),
                    'valid_until' => $rule->valid_until?->toDateString(),
                    'currency' => 'KRW',
                    'rounding' => 'ceil_final_won',
                ],
                'rationale' => trim($rationale),
                'decision' => $decision,
                'decision_reason' => $decisionReason === null ? null : trim($decisionReason),
            ]);
        });
    }
}
