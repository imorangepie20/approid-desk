<?php

namespace App\Actions;

use App\Enums\AttachmentDeletionEventType;
use App\Enums\AttachmentDeletionFailure;
use App\Enums\AttachmentDeletionStatus;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\AttachmentDeletionEvent;
use App\Models\User;
use App\Models\WorkRequest;
use App\Services\DeleteAttachmentContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeleteAttachment
{
    private const STALE_AFTER_MINUTES = 5;

    public function __construct(private ?DeleteAttachmentContent $content = null) {}

    public function handle(User $actor, Attachment $attachment, string $reason): Attachment
    {
        $attemptId = null;
        $attachment = DB::transaction(function () use ($actor, $attachment, $reason, &$attemptId): Attachment {
            $locator = Attachment::query()->findOrFail($attachment->id);
            WorkRequest::query()->lockForUpdate()->findOrFail($locator->work_request_id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $attachment = Attachment::query()->lockForUpdate()->findOrFail($locator->id);
            Gate::forUser($actor)->authorize('delete', $attachment);
            if ($attachment->deletion_status === AttachmentDeletionStatus::Deleted) {
                return $attachment;
            }
            if ($attachment->scan_status === AttachmentScanStatus::Scanning) {
                throw ValidationException::withMessages(['attachment' => '악성 파일 검사가 진행 중인 첨부파일은 삭제할 수 없습니다.']);
            }
            $reason = Validator::make(['reason' => trim($reason)], [
                'reason' => ['required', 'string', 'max:1000'],
            ])->validate()['reason'];
            if ($attachment->deletion_status === AttachmentDeletionStatus::Pending) {
                if ($attachment->deletion_requested_at === null
                    || $attachment->deletion_requested_at->isAfter(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                    throw ValidationException::withMessages(['attachment' => '첨부파일 삭제가 이미 진행 중입니다.']);
                }
                $this->event($attachment, $actor, AttachmentDeletionEventType::Failed,
                    $attachment->deletion_attempt_id, failure: AttachmentDeletionFailure::Interrupted);
            }

            $attemptId = (string) Str::uuid();
            $attachment->forceFill(['deletion_status' => AttachmentDeletionStatus::Pending,
                'deletion_attempt_id' => $attemptId, 'deletion_requested_by' => $actor->id,
                'deletion_requested_at' => now(), 'deleted_at' => null])->save();
            $this->event($attachment, $actor, AttachmentDeletionEventType::Requested, $attemptId, $reason);

            return $attachment;
        });
        if ($attemptId === null) {
            return $attachment;
        }

        $failure = ($this->content ??= new DeleteAttachmentContent)->handle($attachment);
        $attachment = DB::transaction(function () use ($actor, $attachment, $attemptId, $failure): Attachment {
            WorkRequest::query()->lockForUpdate()->findOrFail($attachment->work_request_id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $attachment = Attachment::query()->lockForUpdate()->findOrFail($attachment->id);
            if ($attachment->deletion_status !== AttachmentDeletionStatus::Pending
                || $attachment->deletion_attempt_id !== $attemptId) {
                throw ValidationException::withMessages(['attachment' => '첨부파일 삭제 상태가 변경되어 결과를 기록할 수 없습니다.']);
            }
            $event = $failure === null ? AttachmentDeletionEventType::Succeeded : AttachmentDeletionEventType::Failed;
            $attachment->forceFill(['deletion_status' => $failure === null
                ? AttachmentDeletionStatus::Deleted : AttachmentDeletionStatus::Failed,
                'deleted_at' => $failure === null ? now() : null])->save();
            $this->event($attachment, $actor, $event, $attemptId, failure: $failure);

            return $attachment;
        });
        if ($failure !== null) {
            throw ValidationException::withMessages(['attachment' => '첨부파일 저장소 삭제에 실패했습니다. 다시 시도해 주세요.']);
        }

        return $attachment;
    }

    private function event(Attachment $attachment, User $actor, AttachmentDeletionEventType $type,
        ?string $attemptId, ?string $reason = null, ?AttachmentDeletionFailure $failure = null): void
    {
        if ($attemptId === null) {
            throw ValidationException::withMessages(['attachment' => '첨부파일 삭제 시도 정보가 없습니다.']);
        }
        $event = new AttachmentDeletionEvent;
        $event->forceFill(['company_id' => $attachment->company_id, 'attachment_id' => $attachment->id,
            'actor_id' => $actor->id, 'attempt_id' => $attemptId, 'event_type' => $type,
            'reason' => $reason, 'failure_code' => $failure, 'occurred_at' => now()])->save();
    }
}
