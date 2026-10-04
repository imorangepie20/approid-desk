<?php

namespace App\Actions;

use App\Enums\MajorIncidentEventType;
use App\Enums\NotificationType;
use App\Models\MajorIncidentEvent;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordMajorIncidentEvent
{
    public function handle(
        User $actor,
        WorkRequest $workRequest,
        MajorIncidentEventType $type,
        string $summary,
        string $details,
        CarbonInterface $occurredAt,
    ): MajorIncidentEvent {
        $summary = trim($summary);
        $details = trim($details);

        return DB::transaction(function () use ($actor, $workRequest, $type, $summary, $details, $occurredAt): MajorIncidentEvent {
            $lockedRequest = WorkRequest::query()->lockForUpdate()->findOrFail($workRequest->id);
            $currentActor = User::query()->findOrFail($actor->id);

            Gate::forUser($currentActor)->authorize('create', [MajorIncidentEvent::class, $lockedRequest]);

            $errors = [];
            if ($summary === '') {
                $errors['summary'] = '요약을 입력해 주세요.';
            } elseif (mb_strlen($summary) > 255) {
                $errors['summary'] = '요약은 255자 이하여야 합니다.';
            }
            if ($details === '') {
                $errors['details'] = '상세 내용을 입력해 주세요.';
            } elseif (mb_strlen($details) > 10000) {
                $errors['details'] = '상세 내용은 10,000자 이하여야 합니다.';
            }
            if ($occurredAt->isBefore($lockedRequest->requested_at)) {
                $errors['occurred_at'] = '발생 시각은 실제 요청 일시보다 빠를 수 없습니다.';
            }
            if ($occurredAt->isAfter(now())) {
                $errors['occurred_at'] = '발생 시각은 현재보다 늦을 수 없습니다.';
            }
            if ($type === MajorIncidentEventType::FirstResponse
                && $lockedRequest->majorIncidentEvents()->where('event_type', $type->value)->exists()) {
                $errors['event_type'] = '최초 응답은 한 번만 기록할 수 있습니다.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $event = MajorIncidentEvent::query()->create([
                'company_id' => $lockedRequest->company_id,
                'work_request_id' => $lockedRequest->id,
                'recorded_by' => $currentActor->id,
                'event_type' => $type,
                'summary' => $summary,
                'details' => $details,
                'occurred_at' => $occurredAt,
            ]);

            (new SendBusinessNotification)->handle(
                NotificationType::MajorIncidentUpdated,
                $lockedRequest,
                $currentActor,
                ['major_incident_event_id' => $event->id],
            );

            return $event;
        });
    }
}
