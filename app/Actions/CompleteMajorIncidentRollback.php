<?php

namespace App\Actions;

use App\Enums\MajorIncidentRollbackOutcome;
use App\Enums\NotificationType;
use App\Models\MajorIncidentRollback;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CompleteMajorIncidentRollback
{
    public function handle(
        User $actor,
        WorkRequest $workRequest,
        MajorIncidentRollback $rollback,
        MajorIncidentRollbackOutcome $outcome,
        string $resultSummary,
        string $resultDetails,
        CarbonInterface $completedAt,
        bool $confirmed,
    ): MajorIncidentRollback {
        $resultSummary = trim($resultSummary);
        $resultDetails = trim($resultDetails);

        return DB::transaction(function () use ($actor, $workRequest, $rollback, $outcome, $resultSummary, $resultDetails, $completedAt, $confirmed): MajorIncidentRollback {
            $lockedRequest = WorkRequest::query()->lockForUpdate()->findOrFail($workRequest->id);
            $lockedRollback = MajorIncidentRollback::query()
                ->where('work_request_id', $lockedRequest->id)
                ->lockForUpdate()
                ->findOrFail($rollback->id);
            $currentActor = User::query()->findOrFail($actor->id);

            Gate::forUser($currentActor)->authorize('complete', $lockedRollback);

            $errors = [];
            if ($resultSummary === '') {
                $errors['rollback_result_summary'] = '결과 요약을 입력해 주세요.';
            } elseif (mb_strlen($resultSummary) > 255) {
                $errors['rollback_result_summary'] = '결과 요약은 255자 이하여야 합니다.';
            }
            if ($resultDetails === '') {
                $errors['rollback_result_details'] = '결과 상세를 입력해 주세요.';
            } elseif (mb_strlen($resultDetails) > 10000) {
                $errors['rollback_result_details'] = '결과 상세는 10,000자 이하여야 합니다.';
            }
            if ($completedAt->isBefore($lockedRollback->started_at)) {
                $errors['rollback_completed_at'] = '완료 시각은 시작 시각보다 빠를 수 없습니다.';
            }
            if ($completedAt->isAfter(now())) {
                $errors['rollback_completed_at'] = '완료 시각은 현재보다 늦을 수 없습니다.';
            }
            if (! $confirmed) {
                $errors['rollback_result_confirmed'] = '결과와 검증 내용을 확인해 주세요.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            DB::table('major_incident_rollbacks')
                ->where('id', $lockedRollback->id)
                ->whereNull('outcome')
                ->update([
                    'outcome' => $outcome->value,
                    'result_summary' => $resultSummary,
                    'result_details' => $resultDetails,
                    'completed_by' => $currentActor->id,
                    'completed_at' => $completedAt,
                    'updated_at' => now(),
                ]);

            (new SendBusinessNotification)->handle(
                NotificationType::MajorIncidentRollbackUpdated,
                $lockedRequest,
                $currentActor,
                ['major_incident_rollback_id' => $lockedRollback->id, 'rollback_phase' => 'completed'],
            );

            return $lockedRollback->fresh(['starter', 'completer']) ?? $lockedRollback;
        });
    }
}
