<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCustomerADemoEstimate
{
    public const ESTIMATED_MINUTES = 180;

    public const HOURLY_RATE = 60000;

    public const RULE_CRITERIA = '포트폴리오 데모 시나리오 A의 일반 기능 요청 기준';

    public const RATIONALE = '요구사항 분석, 알림 설정 화면 구현, 저장 처리와 반응형 검증을 포함한 예상시간입니다.';

    public const INCLUDED_SCOPE = "알림 채널별 수신 설정 화면\n설정 저장 및 재조회 처리\n모바일 반응형 표시와 기능 검증";

    public const EXCLUDED_SCOPE = "외부 문자 메시지 발송 비용\n고객사별 별도 디자인 제작\n기존 범위를 벗어난 신규 알림 채널 연동";

    public function handle(User $actor): EstimateVersion
    {
        return DB::transaction(function () use ($actor): EstimateVersion {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);

            if (! $actor->is_active || ! $actor->role->hasPermission(Permission::CreateEstimates)) {
                throw new AuthorizationException('활성 견적 작성 계정만 데모 견적을 제출할 수 있습니다.');
            }

            $request = WorkRequest::query()
                ->where('source_reference', CreateCustomerADemoRequest::REQUEST_REFERENCE)
                ->lockForUpdate()
                ->first();

            if (! $request instanceof WorkRequest) {
                throw ValidationException::withMessages([
                    'demo_request' => '4.27 고객사 A 데모 요청을 먼저 생성해 주세요.',
                ]);
            }

            $existing = $request->estimateVersions()
                ->where('included_scope', self::INCLUDED_SCOPE)
                ->first();

            if ($existing instanceof EstimateVersion) {
                if ($existing->submitted_at === null || $existing->estimated_minutes !== self::ESTIMATED_MINUTES) {
                    throw ValidationException::withMessages([
                        'estimate' => '기존 데모 견적의 제출 상태와 예상시간을 확인해 주세요.',
                    ]);
                }

                return $existing;
            }

            if ($request->status !== WorkRequestStatus::Received || $request->estimateVersions()->exists()) {
                throw ValidationException::withMessages([
                    'estimate' => '견적이 없는 접수 상태의 데모 요청에서만 최초 견적을 만들 수 있습니다.',
                ]);
            }

            $month = ContractMonth::query()
                ->where('service_contract_id', $request->service_contract_id)
                ->orderBy('month')
                ->lockForUpdate()
                ->first();

            if (! $month instanceof ContractMonth || $month->status !== 'open') {
                throw ValidationException::withMessages([
                    'usage_month' => '데모 요청에 연결된 열린 계약 월이 필요합니다.',
                ]);
            }

            $rules = PricingRule::query()
                ->where('work_type', $request->type->value)
                ->where('difficulty', WorkDifficulty::Normal->value)
                ->whereDate('valid_from', '<=', today())
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
                ->lockForUpdate()
                ->get();

            if ($rules->isEmpty()) {
                PricingRule::query()->create([
                    'work_type' => $request->type,
                    'difficulty' => WorkDifficulty::Normal,
                    'hourly_rate' => self::HOURLY_RATE,
                    'urgent_surcharge_bps' => 2500,
                    'urgent_criteria' => self::RULE_CRITERIA,
                    'valid_from' => today(),
                    'valid_until' => null,
                ]);
            }

            $assessment = (new AssessRequestPricing)->handle(
                $actor,
                $request,
                WorkDifficulty::Normal,
                self::ESTIMATED_MINUTES,
                today(),
                self::RATIONALE,
            );
            $estimate = (new CreateEstimateVersion)->handle(
                $actor,
                $request,
                $assessment,
                self::INCLUDED_SCOPE,
                self::EXCLUDED_SCOPE,
                today()->addWeek(),
                $month->month,
            );

            (new TransitionWorkRequest)->handle($actor, $request, WorkRequestStatus::Estimating);
            (new SubmitEstimateVersion)->handle($actor, $estimate);
            (new TransitionWorkRequest)->handle($actor, $request, WorkRequestStatus::AwaitingApproval);

            return $estimate->fresh() ?? $estimate;
        }, 5);
    }
}
