<?php

namespace App\Actions;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Enums\WorkRequestActivityType;
use App\Enums\WorkRequestStatus as Status;
use App\Models\EstimateApproval;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use App\Services\ReleaseRemainingReservation;
use App\Services\WorkRequestTransitionRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransitionWorkRequest
{
    public function handle(User $actor, WorkRequest $request, Status $to, ?string $reason = null, bool $isFreeRework = false): WorkRequestStatusChange
    {
        return DB::transaction(function () use ($actor, $request, $to, $reason, $isFreeRework): WorkRequestStatusChange {
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($request->id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('view', $request);
            $from = $request->status;
            $customerDecision = ($from === Status::AwaitingApproval && in_array($to, [Status::Queued, Status::Estimating], true))
                || ($from === Status::AwaitingReview && in_array($to, [Status::Completed, Status::InProgress], true));
            if ($customerDecision ? $actor->role !== UserRole::CustomerAdmin : ! $actor->role->isSystemRole()) {
                throw new AuthorizationException;
            }

            $rules = new WorkRequestTransitionRules;
            $heldFrom = $from === Status::OnHold
                ? $request->statusChanges()->where('to_status', Status::OnHold->value)->latest('id')->first()?->from_status
                : null;
            $reason = $reason === null ? null : trim($reason);
            if (! in_array($to, $rules->destinations($from, $heldFrom), true)
                || ($rules->requiresReason($from, $to) && blank($reason))
                || ($from === Status::Completed && ! $isFreeRework)
                || ($isFreeRework && $from !== Status::Completed)) {
                throw ValidationException::withMessages(['status' => '허용된 전환과 변경 사유, 무상 수정 여부를 확인해 주세요.']);
            }

            $latest = $request->latestEstimateVersion()->first();
            $estimateId = $from === Status::AwaitingApproval
                ? $latest?->id
                : $request->approvedEstimateVersion()->first()?->id;
            if ($to === Status::AwaitingApproval) {
                if ($latest === null || $latest->submitted_at === null) {
                    throw ValidationException::withMessages(['estimate' => '최신 제출 견적이 필요합니다.']);
                }
                if ($request->approved_estimate_version_id === $latest->id) {
                    throw ValidationException::withMessages(['estimate' => '현재 승인 견적과 다른 최신 제출 견적이 필요합니다.']);
                }
                $estimateId = $latest->id;
            }
            if (in_array($to, [Status::Queued, Status::InProgress, Status::Completed], true)) {
                $approved = $request->approvedEstimateVersion()->first();
                if ($approved === null || $latest === null || $approved->id !== $latest->id
                    || ! EstimateApproval::query()->where('estimate_version_id', $approved->id)->exists()) {
                    throw ValidationException::withMessages(['estimate' => '최신 견적의 고객 승인이 필요합니다.']);
                }
                if (in_array($to, [Status::Queued, Status::InProgress], true)) {
                    $contract = ServiceContract::query()->where('company_id', $request->company_id)->lockForUpdate()->find($request->service_contract_id);
                    if ($contract === null || ! $contract->permitsWork()) {
                        throw ValidationException::withMessages(['service_contract_id' => '서명 확인된 유효한 계약이 필요합니다.']);
                    }
                }
                $estimateId = $approved->id;
            }

            // The transition owns the audit rows; avoid duplicate observer activity.
            $request->forceFill(['status' => $to])->saveQuietly();
            $change = new WorkRequestStatusChange;
            $change->forceFill([
                'company_id' => $request->company_id,
                'work_request_id' => $request->id,
                'changed_by' => $actor->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'estimate_version_id' => $estimateId,
                'is_free_rework' => $isFreeRework,
                'occurred_at' => now(),
            ])->save();
            if (in_array($to, [Status::Completed, Status::Cancelled], true)) {
                (new ReleaseRemainingReservation)->handle($request, $change, $actor);
            }
            $request->activities()->create([
                'company_id' => $request->company_id,
                'actor_id' => $actor->id,
                'type' => WorkRequestActivityType::StatusChanged,
                'summary' => $from->label().' → '.$to->label(),
                'before_values' => ['status' => $from->value],
                'after_values' => ['status' => $to->value, 'status_change_id' => $change->id, 'reason' => $reason],
                'occurred_at' => $change->occurred_at,
            ]);

            $notificationType = $this->notificationType($from, $to);
            if ($notificationType !== null) {
                (new SendBusinessNotification)->handle(
                    $notificationType,
                    $request,
                    $actor,
                    ['status_change_id' => $change->id],
                );
            }

            return $change;
        }, 5);
    }

    private function notificationType(Status $from, Status $to): ?NotificationType
    {
        return match (true) {
            $to === Status::Cancelled => NotificationType::RequestCancelled,
            $to === Status::AwaitingReview => NotificationType::ReviewRequested,
            $to === Status::Completed => NotificationType::ReviewCompleted,
            $to === Status::OnHold,
            $from === Status::InProgress && $to === Status::Queued => NotificationType::WorkPaused,
            $from === Status::OnHold,
            in_array($from, [Status::AwaitingReview, Status::Completed], true) && $to === Status::InProgress => NotificationType::WorkResumed,
            $to === Status::InProgress => NotificationType::WorkStarted,
            default => null,
        };
    }
}
