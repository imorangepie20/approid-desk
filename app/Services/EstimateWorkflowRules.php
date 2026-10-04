<?php

namespace App\Services;

use App\Enums\WorkRequestStatus as Status;
use App\Models\EstimateVersion;
use App\Models\WorkRequest;

class EstimateWorkflowRules
{
    public function canWrite(WorkRequest $request): bool
    {
        if ($request->approved_estimate_version_id === null) {
            return in_array($request->status, [Status::Received, Status::Estimating], true);
        }

        return in_array($request->status, [Status::Estimating, Status::Queued, Status::InProgress], true);
    }

    public function canSubmit(WorkRequest $request, EstimateVersion $estimate): bool
    {
        return $this->canWrite($request) && in_array($request->status, [Status::Estimating, Status::Queued, Status::InProgress], true)
            && $estimate->submitted_at === null && $this->isLatest($request, $estimate);
    }

    public function canDecide(WorkRequest $request, EstimateVersion $estimate): bool
    {
        return $request->status === Status::AwaitingApproval
            && $request->approved_estimate_version_id !== $estimate->id
            && $estimate->submitted_at !== null && $this->isLatest($request, $estimate);
    }

    private function isLatest(WorkRequest $request, EstimateVersion $estimate): bool
    {
        return $estimate->work_request_id === $request->id
            && (int) $request->estimateVersions()->max('version') === $estimate->version;
    }
}
