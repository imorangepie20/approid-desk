<?php

namespace App\Observers;

use App\Enums\WorkRequestActivityType;
use App\Enums\WorkRequestStatus;
use App\Models\ServiceContract;
use App\Models\WorkRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class WorkRequestObserver
{
    private const AUDITED_ATTRIBUTES = [
        'company_id',
        'project_id',
        'service_contract_id',
        'submitted_by',
        'assigned_to',
        'parent_request_id',
        'title',
        'requirements',
        'type',
        'priority',
        'is_urgent',
        'desired_due_date',
        'intake_channel',
        'source_reference',
        'intake_summary',
        'status',
        'requested_at',
        'registered_at',
        'late_entry_reason',
    ];

    public function saving(WorkRequest $workRequest): void
    {
        if (! $workRequest->isDirty(['status', 'service_contract_id', 'company_id'])
            || ! in_array($workRequest->status, [WorkRequestStatus::Queued, WorkRequestStatus::InProgress], true)) {
            return;
        }

        $contract = ServiceContract::query()
            ->where('company_id', $workRequest->company_id)
            ->find($workRequest->service_contract_id);

        if ($contract === null || ! $contract->permitsWork()) {
            throw ValidationException::withMessages(['service_contract_id' => '서명 확인이 완료된 자사의 유효한 계약이 필요합니다.']);
        }
    }

    public function created(WorkRequest $workRequest): void
    {
        $workRequest->activities()->create([
            'company_id' => $workRequest->company_id,
            'actor_id' => $this->actorId() ?? $workRequest->submitted_by,
            'type' => WorkRequestActivityType::RequestCreated,
            'summary' => '요청이 등록되었습니다.',
            'before_values' => null,
            'after_values' => Arr::only($workRequest->getAttributes(), self::AUDITED_ATTRIBUTES),
            'occurred_at' => now(),
        ]);
    }

    public function updated(WorkRequest $workRequest): void
    {
        $changes = Arr::except($workRequest->getChanges(), ['updated_at']);

        $this->recordChange(
            $workRequest,
            Arr::only($changes, ['assigned_to']),
            WorkRequestActivityType::AssigneeChanged,
            '담당자가 변경되었습니다.',
        );
        $this->recordChange(
            $workRequest,
            Arr::only($changes, ['status']),
            WorkRequestActivityType::StatusChanged,
            '요청 상태가 변경되었습니다.',
        );
        $this->recordChange(
            $workRequest,
            Arr::except($changes, ['assigned_to', 'status']),
            WorkRequestActivityType::RequestUpdated,
            '요청 내용이 수정되었습니다.',
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function recordChange(
        WorkRequest $workRequest,
        array $changes,
        WorkRequestActivityType $type,
        string $summary,
    ): void {
        if ($changes === []) {
            return;
        }

        $before = [];
        $after = [];

        foreach (array_keys($changes) as $attribute) {
            $before[$attribute] = $workRequest->getRawOriginal($attribute);
            $after[$attribute] = $workRequest->getAttributes()[$attribute] ?? null;
        }

        $workRequest->activities()->create([
            'company_id' => $workRequest->company_id,
            'actor_id' => $this->actorId(),
            'type' => $type,
            'summary' => $summary,
            'before_values' => $before,
            'after_values' => $after,
            'occurred_at' => now(),
        ]);
    }

    private function actorId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }
}
