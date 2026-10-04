<?php

namespace App\Models;

use App\Enums\AttachmentDeletionEventType;
use App\Enums\AttachmentDeletionFailure;
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
 * @property int $actor_id
 * @property string $attempt_id
 * @property AttachmentDeletionEventType $event_type
 * @property string|null $reason
 * @property AttachmentDeletionFailure|null $failure_code
 * @property CarbonInterface $occurred_at
 */
class AttachmentDeletionEvent extends Model
{
    use HasCompanyVisibility {
        scopeVisibleTo as private scopeCompanyVisibleTo;
    }
    use ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['event_type' => AttachmentDeletionEventType::class,
            'failure_code' => AttachmentDeletionFailure::class, 'occurred_at' => 'datetime'];
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

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
