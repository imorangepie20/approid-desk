<?php

namespace App\Actions;

use App\Enums\PricingDecision;
use App\Models\EstimateVersion;
use App\Models\PricingAssessment;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateEstimateVersion
{
    public function handle(User $actor, WorkRequest $request, PricingAssessment $assessment, string $includedScope, string $excludedScope, CarbonInterface $scheduledOn, CarbonInterface $usageMonth): EstimateVersion
    {
        Gate::forUser($actor)->authorize('create', [EstimateVersion::class, $request]);
        if (trim($includedScope) === '' || trim($excludedScope) === '' || $usageMonth->day !== 1) {
            throw ValidationException::withMessages(['estimate' => '포함·제외 범위와 사용 대상 월의 첫 날짜를 입력해 주세요.']);
        }

        return DB::transaction(function () use ($actor, $request, $assessment, $includedScope, $excludedScope, $scheduledOn, $usageMonth): EstimateVersion {
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($actor)->authorize('create', [EstimateVersion::class, $request]);
            $assessment = PricingAssessment::query()->lockForUpdate()->findOrFail($assessment->id);
            if ($assessment->work_request_id !== $request->id || $assessment->company_id !== $request->company_id
                || $assessment->decision !== PricingDecision::Feasible) {
                throw ValidationException::withMessages(['pricing_assessment' => '이 요청의 견적 가능한 가격 판단 기록이 필요합니다.']);
            }

            $estimate = new EstimateVersion;
            $estimate->forceFill([
                'company_id' => $request->company_id,
                'work_request_id' => $request->id,
                'pricing_assessment_id' => $assessment->id,
                'version' => (int) $request->estimateVersions()->max('version') + 1,
                'included_scope' => trim($includedScope),
                'excluded_scope' => trim($excludedScope),
                'estimated_minutes' => $assessment->estimated_minutes,
                'scheduled_on' => $scheduledOn->toDateString(),
                'usage_month' => $usageMonth->toDateString(),
                'amount' => $assessment->amount,
                'rate_snapshot' => $assessment->rate_snapshot,
                'pricing_rationale' => $assessment->rationale,
                'created_by' => $actor->id,
            ])->save();

            return $estimate;
        });
    }
}
