<?php

namespace App\Models;

use App\Enums\AttachmentDeletionStatus;
use App\Enums\AttachmentScanStatus;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int|null $work_request_comment_id
 * @property int|null $estimate_version_id
 * @property string|null $original_name
 * @property int|null $size_bytes
 * @property string|null $mime_type
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $content_sha256
 * @property int|null $uploaded_by
 * @property CarbonInterface|null $uploaded_at
 * @property AttachmentScanStatus $scan_status
 * @property string|null $scan_attempt_id
 * @property CarbonInterface|null $scan_requested_at
 * @property CarbonInterface|null $scanned_at
 * @property AttachmentDeletionStatus $deletion_status
 * @property string|null $deletion_attempt_id
 * @property int|null $deletion_requested_by
 * @property CarbonInterface|null $deletion_requested_at
 * @property CarbonInterface|null $deleted_at
 */
class Attachment extends Model
{
    protected $attributes = ['scan_status' => 'pending', 'deletion_status' => 'active'];

    use HasCompanyVisibility {
        scopeVisibleTo as private scopeCompanyVisibleTo;
    }

    protected static function booted(): void
    {
        static::updating(function (Attachment $attachment): void {
            if ($attachment->isDirty(['company_id', 'work_request_id', 'work_request_comment_id', 'estimate_version_id'])) {
                throw new LogicException('첨부파일의 업무 자료 연결은 변경할 수 없습니다.');
            }
            if ($attachment->getRawOriginal('uploaded_by') !== null
                && ($attachment->isDirty(['original_name', 'size_bytes', 'mime_type', 'storage_disk', 'storage_path',
                    'uploaded_by', 'uploaded_at'])
                    || ($attachment->getRawOriginal('content_sha256') !== null
                        && $attachment->isDirty('content_sha256')))) {
                throw new LogicException('등록된 첨부파일 정보는 변경할 수 없습니다.');
            }
        });
        static::deleting(fn () => throw new LogicException('첨부파일 감사 기록은 삭제할 수 없습니다.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'uploaded_by' => 'integer', 'uploaded_at' => 'datetime',
            'scan_status' => AttachmentScanStatus::class, 'scanned_at' => 'datetime',
            'scan_requested_at' => 'datetime',
            'deletion_status' => AttachmentDeletionStatus::class, 'deletion_requested_by' => 'integer',
            'deletion_requested_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function deletionRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deletion_requested_by');
    }

    /** @return HasMany<AttachmentDeletionEvent, $this> */
    public function deletionEvents(): HasMany
    {
        return $this->hasMany(AttachmentDeletionEvent::class);
    }

    /** @return HasMany<AttachmentScanEvent, $this> */
    public function scanEvents(): HasMany
    {
        return $this->hasMany(AttachmentScanEvent::class);
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query = $this->scopeCompanyVisibleTo($query, $user);

        return $user->role->isSystemRole() ? $query : $query->where(function (Builder $query): void {
            $query->whereNull('estimate_version_id')
                ->orWhereHas('estimateVersion', fn (Builder $estimate) => $estimate->whereNotNull('submitted_at'));
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<WorkRequestComment, $this> */
    public function workRequestComment(): BelongsTo
    {
        return $this->belongsTo(WorkRequestComment::class);
    }

    /** @return BelongsTo<EstimateVersion, $this> */
    public function estimateVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateVersion::class);
    }
}
