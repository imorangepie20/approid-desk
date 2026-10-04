<?php

namespace App\Services;

use App\Enums\AttachmentDeletionStatus;
use App\Models\Attachment;
use App\Models\WorkRequest;
use Illuminate\Validation\ValidationException;

class AttachmentLimits
{
    public function validateSize(int|false $bytes): void
    {
        $limit = $this->limit('max_file_bytes');
        if ($bytes === false || $bytes < 0 || $bytes > $limit) {
            throw ValidationException::withMessages(['file' => '파일별 허용 용량을 초과했거나 용량을 확인할 수 없습니다.']);
        }
    }

    // Caller holds the request row lock for the entire registration transaction.
    public function validateCount(WorkRequest $request): void
    {
        $limit = $this->limit('max_per_request');
        // A locking read sees committed additions even after a repeatable-read snapshot.
        $count = count(Attachment::query()->where('work_request_id', $request->id)
            ->where('deletion_status', '!=', AttachmentDeletionStatus::Deleted->value)
            ->orderBy('id')->limit($limit)->lockForUpdate()->get(['id'])->all());
        if ($count >= $limit) {
            throw ValidationException::withMessages(['file' => '이 업무 요청의 첨부파일 허용 개수를 초과했습니다.']);
        }
    }

    private function limit(string $key): int
    {
        $value = config('attachments.'.$key);
        if (! is_int($value) || $value < 1) {
            throw ValidationException::withMessages(['file' => '첨부파일 제한 설정을 확인해야 합니다.']);
        }

        return $value;
    }
}
