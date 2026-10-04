<?php

namespace App\Models;

use App\Enums\AttachmentScanEventType;
use App\Enums\AttachmentScanFailure;
use App\Models\Concerns\HasCompanyVisibility;
use App\Models\Concerns\ImmutableAuditRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $attachment_id
 * @property string $attempt_id
 * @property AttachmentScanEventType $event_type
 * @property string $scanner
 * @property string|null $signature
 * @property AttachmentScanFailure|null $failure_code
 * @property CarbonInterface $occurred_at
 */
class AttachmentScanEvent extends Model
{
    use HasCompanyVisibility {
        scopeVisibleTo as private scopeCompanyVisibleTo;
    }
    use ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => AttachmentScanEventType::class,
            'failure_code' => AttachmentScanFailure::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query = $this->scopeCompanyVisibleTo($query, $user);

        return $query->whereHas('attachment', fn (Builder $attachment) => $attachment->visibleTo($user));
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
