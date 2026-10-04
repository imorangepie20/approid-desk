<?php

namespace App\Services;

use App\Models\EstimateVersion;
use App\Models\PricingAssessment;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class RequestEstimateHistory
{
    /** @return list<array{kind: string, at: CarbonInterface, record: Model}> */
    public function forRequest(User $user, WorkRequest $request): array
    {
        Gate::forUser($user)->authorize('view', $request);
        $events = [];
        $estimates = EstimateVersion::query()->visibleTo($user)->where('work_request_id', $request->id)
            ->with(['creator:id,name', 'approval' => fn ($query) => $query
                ->select(['id', 'estimate_version_id', 'approved_by', 'approver_role', 'approved_at', 'approval_text'])
                ->with('approver:id,name')])->orderBy('version')->get();
        foreach ($estimates as $estimate) {
            $events[] = ['kind' => 'estimate', 'at' => $estimate->submitted_at ?? $estimate->created_at, 'record' => $estimate];
            if ($estimate->approval !== null) {
                $events[] = ['kind' => 'approval', 'at' => $estimate->approval->approved_at, 'record' => $estimate];
            }
        }
        if ($user->role->isSystemRole()) {
            foreach (PricingAssessment::query()->visibleTo($user)->where('work_request_id', $request->id)->with('assessor:id,name')->orderBy('id')->get() as $assessment) {
                if (Gate::forUser($user)->allows('view', $assessment)) {
                    $events[] = ['kind' => 'assessment', 'at' => $assessment->created_at, 'record' => $assessment];
                }
            }
        }
        $contract = $request->serviceContract;
        $confirmedAt = $contract?->signature_confirmed_at;
        if ($contract !== null && $confirmedAt !== null) {
            $contract->load('signatureConfirmer:id,name');
            $events[] = ['kind' => 'contract', 'at' => $confirmedAt, 'record' => $contract];
        }
        foreach ($request->statusChanges()->visibleTo($user)->with('actor:id,name')->orderBy('id')->get() as $change) {
            $events[] = ['kind' => 'status', 'at' => $change->occurred_at, 'record' => $change];
        }

        // Stable reverse insertion order resolves timestamps with equal seconds.
        return array_values(collect(array_reverse($events))->sortByDesc(fn (array $event): string => $event['at']->format('Y-m-d H:i:s.u'))->all());
    }
}
