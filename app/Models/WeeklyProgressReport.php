<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasCompanyVisibility;
use App\Models\Concerns\ImmutableAuditRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property int $open_request_count
 * @property int $new_request_count
 * @property int $changed_request_count
 * @property int $completed_request_count
 * @property array<string, int> $status_counts
 * @property int $recipient_count
 * @property CarbonInterface $generated_at
 * @property-read Company $company
 */
class WeeklyProgressReport extends Model
{
    use HasCompanyVisibility, ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'open_request_count' => 'integer',
            'new_request_count' => 'integer',
            'changed_request_count' => 'integer',
            'completed_request_count' => 'integer',
            'status_counts' => 'array',
            'recipient_count' => 'integer',
            'generated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function canBeReceivedBy(User $user): bool
    {
        if (! $user->canAccessWorkspace()) {
            return false;
        }

        return $user->role->isSystemRole()
            || ($user->role === UserRole::CustomerAdmin && $user->company_id === $this->company_id);
    }
}
