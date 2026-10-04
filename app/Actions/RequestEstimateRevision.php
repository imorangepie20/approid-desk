<?php

namespace App\Actions;

use App\Enums\NotificationType;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use App\Services\EstimateWorkflowRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RequestEstimateRevision
{
    public function handle(User $actor, EstimateVersion $estimate, string $reason): WorkRequestStatusChange
    {
        Gate::forUser($actor)->authorize('requestRevision', $estimate);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 10000) {
            throw ValidationException::withMessages(['reason' => '수정 요청 사유를 10,000자 이내로 입력해 주세요.']);
        }

        return DB::transaction(function () use ($actor, $estimate, $reason): WorkRequestStatusChange {
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($estimate->work_request_id);
            $estimate = EstimateVersion::query()->lockForUpdate()->findOrFail($estimate->id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('requestRevision', $estimate);
            if (! (new EstimateWorkflowRules)->canDecide($request, $estimate)) {
                throw ValidationException::withMessages(['estimate' => '승인 대기 상태의 최신 미승인 제출 견적만 수정 요청할 수 있습니다.']);
            }

            $change = (new TransitionWorkRequest)->handle($actor, $request, WorkRequestStatus::Estimating, $reason);
            (new SendBusinessNotification)->handle(
                NotificationType::EstimateRevisionRequested,
                $request,
                $actor,
                ['estimate_version_id' => $estimate->id],
            );

            return $change;
        }, 5);
    }
}
