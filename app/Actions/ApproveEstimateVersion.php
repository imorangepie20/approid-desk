<?php

namespace App\Actions;

use App\Enums\NotificationType;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use App\Services\EstimateWorkflowRules;
use App\Services\ReplaceEstimateReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApproveEstimateVersion
{
    public const APPROVAL_TEXT = '견적의 포함·제외 범위, 예상시간, 금액, 예정일과 사용 대상 월을 확인하고 승인합니다.';

    public function handle(User $actor, EstimateVersion $estimate, string $idempotencyKey, string $approvalText, string $ipAddress, string $userAgent): EstimateApproval
    {
        Gate::forUser($actor)->authorize('approve', $estimate);
        if (! Str::isUuid($idempotencyKey) || $approvalText !== self::APPROVAL_TEXT
            || filter_var($ipAddress, FILTER_VALIDATE_IP) === false
            || trim($userAgent) === '' || mb_strlen($userAgent) > 1024) {
            throw ValidationException::withMessages(['approval' => '승인 문구, 요청 키와 접속 정보를 확인해 주세요.']);
        }
        $idempotencyKey = strtolower($idempotencyKey);

        return DB::transaction(function () use ($actor, $estimate, $idempotencyKey, $ipAddress, $userAgent): EstimateApproval {
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($estimate->work_request_id);
            $estimate = EstimateVersion::query()->lockForUpdate()->findOrFail($estimate->id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('approve', $estimate);

            $existing = EstimateApproval::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->estimate_version_id !== $estimate->id || $existing->approved_by !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => '이미 다른 승인에 사용된 요청 키입니다.']);
                }

                return $existing;
            }

            if (! (new EstimateWorkflowRules)->canDecide($request, $estimate)) {
                throw ValidationException::withMessages(['estimate' => '승인 대기 상태의 최신 제출 견적만 승인할 수 있습니다.']);
            }
            if (EstimateApproval::query()->where('estimate_version_id', $estimate->id)->exists()) {
                throw ValidationException::withMessages(['estimate' => '이미 승인된 견적입니다.']);
            }

            $approval = new EstimateApproval;
            $approval->forceFill([
                'company_id' => $estimate->company_id,
                'work_request_id' => $request->id,
                'estimate_version_id' => $estimate->id,
                'approved_by' => $actor->id,
                'approver_role' => $actor->role,
                'approved_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'approval_text' => self::APPROVAL_TEXT,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ])->save();
            (new ReplaceEstimateReservation)->handle($request, $estimate, $approval);
            $request->forceFill(['approved_estimate_version_id' => $estimate->id])->save();
            // The nested transaction stays within this approval transaction.
            (new TransitionWorkRequest)->handle($actor, $request, WorkRequestStatus::Queued);
            (new SendBusinessNotification)->handle(
                NotificationType::EstimateApproved,
                $request,
                $actor,
                ['estimate_version_id' => $estimate->id],
            );

            return $approval;
        }, 5);
    }
}
