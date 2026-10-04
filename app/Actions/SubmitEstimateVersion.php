<?php

namespace App\Actions;

use App\Enums\AttachmentDeletionStatus;
use App\Enums\AttachmentScanStatus;
use App\Enums\NotificationType;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SubmitEstimateVersion
{
    public function handle(User $actor, EstimateVersion $estimate): EstimateVersion
    {
        Gate::forUser($actor)->authorize('submit', $estimate);

        return DB::transaction(function () use ($actor, $estimate): EstimateVersion {
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($estimate->work_request_id);
            $estimate = EstimateVersion::query()->lockForUpdate()->findOrFail($estimate->id);
            Gate::forUser($actor)->authorize('submit', $estimate);
            if ($estimate->submitted_at !== null) {
                return $estimate;
            }
            if ((int) $request->estimateVersions()->max('version') !== $estimate->version) {
                throw ValidationException::withMessages(['estimate' => '최신 견적 버전만 제출할 수 있습니다.']);
            }
            if ($estimate->attachments()->whereIn('deletion_status', [
                AttachmentDeletionStatus::Pending->value,
                AttachmentDeletionStatus::Failed->value,
            ])->exists()) {
                throw ValidationException::withMessages(['attachment' => '삭제 처리가 끝나지 않은 견적 첨부파일이 있습니다.']);
            }
            if ($estimate->attachments()
                ->where('deletion_status', '!=', AttachmentDeletionStatus::Deleted->value)
                ->whereNotNull('uploaded_by')
                ->where('scan_status', '!=', AttachmentScanStatus::Clean->value)
                ->exists()) {
                throw ValidationException::withMessages(['attachment' => '안전 검사가 완료되지 않은 견적 첨부파일이 있습니다.']);
            }
            $estimate->forceFill(['submitted_at' => now()])->save();

            (new SendBusinessNotification)->handle(
                NotificationType::EstimateSubmitted,
                $request,
                $actor,
                ['estimate_version_id' => $estimate->id],
            );

            return $estimate;
        });
    }
}
