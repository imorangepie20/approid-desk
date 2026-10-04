<?php

namespace App\Models;

use App\Models\Concerns\ImmutableAuditRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $notification_delivery_id
 * @property int $requested_by
 * @property CarbonInterface $requested_at
 * @property-read NotificationDelivery $delivery
 * @property-read User $requester
 */
class NotificationDeliveryRetry extends Model
{
    use ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_by' => 'integer',
            'requested_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<NotificationDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class, 'notification_delivery_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
