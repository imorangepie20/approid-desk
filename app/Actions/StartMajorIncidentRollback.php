<?php

namespace App\Actions;

use App\Enums\NotificationType;
use App\Models\MajorIncidentRollback;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StartMajorIncidentRollback
{
    public function handle(
        User $actor,
        WorkRequest $workRequest,
        string $target,
        string $plan,
        string $verificationPlan,
        CarbonInterface $startedAt,
        bool $confirmed,
    ): MajorIncidentRollback {
        $target = trim($target);
        $plan = trim($plan);
        $verificationPlan = trim($verificationPlan);

        return DB::transaction(function () use ($actor, $workRequest, $target, $plan, $verificationPlan, $startedAt, $confirmed): MajorIncidentRollback {
            $lockedRequest = WorkRequest::query()->lockForUpdate()->findOrFail($workRequest->id);
            $currentActor = User::query()->findOrFail($actor->id);

            Gate::forUser($currentActor)->authorize('create', [MajorIncidentRollback::class, $lockedRequest]);

            $errors = [];
            if ($target === '') {
                $errors['rollback_target'] = '롤백 대상을 입력해 주세요.';
            } elseif (mb_strlen($target) > 255) {
                $errors['rollback_target'] = '롤백 대상은 255자 이하여야 합니다.';
            }
            if ($plan === '') {
                $errors['rollback_plan'] = '실행 계획을 입력해 주세요.';
            } elseif (mb_strlen($plan) > 10000) {
                $errors['rollback_plan'] = '실행 계획은 10,000자 이하여야 합니다.';
            }
            if ($verificationPlan === '') {
                $errors['rollback_verification_plan'] = '검증 계획을 입력해 주세요.';
            } elseif (mb_strlen($verificationPlan) > 10000) {
                $errors['rollback_verification_plan'] = '검증 계획은 10,000자 이하여야 합니다.';
            }
            if ($startedAt->isBefore($lockedRequest->requested_at)) {
                $errors['rollback_started_at'] = '시작 시각은 실제 요청 일시보다 빠를 수 없습니다.';
            }
            if ($startedAt->isAfter(now())) {
                $errors['rollback_started_at'] = '시작 시각은 현재보다 늦을 수 없습니다.';
            }
            if (! $confirmed) {
                $errors['rollback_start_confirmed'] = '실행 대상과 계획을 확인해 주세요.';
            }
            if ($lockedRequest->majorIncidentRollbacks()->whereNull('outcome')->exists()) {
                $errors['rollback_target'] = '이미 진행 중인 롤백이 있습니다.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $rollback = MajorIncidentRollback::query()->create([
                'company_id' => $lockedRequest->company_id,
                'work_request_id' => $lockedRequest->id,
                'started_by' => $currentActor->id,
                'target' => $target,
                'plan' => $plan,
                'verification_plan' => $verificationPlan,
                'started_at' => $startedAt,
            ]);

            (new SendBusinessNotification)->handle(
                NotificationType::MajorIncidentRollbackUpdated,
                $lockedRequest,
                $currentActor,
                ['major_incident_rollback_id' => $rollback->id, 'rollback_phase' => 'started'],
            );

            return $rollback;
        });
    }
}
