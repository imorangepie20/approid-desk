<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryFailure;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $event_key
 * @property int $company_id
 * @property int|null $work_request_id
 * @property int|null $weekly_progress_report_id
 * @property NotificationType $notification_type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property NotificationChannel $channel
 * @property array<string, mixed>|null $delivery_data
 * @property NotificationDeliveryStatus $status
 * @property int $attempt_count
 * @property CarbonInterface|null $last_attempted_at
 * @property CarbonInterface|null $failed_at
 * @property NotificationDeliveryFailure|null $failure_code
 * @property CarbonInterface|null $sent_at
 * @property-read WorkRequest $workRequest
 * @property-read WeeklyProgressReport|null $weeklyProgressReport
 * @property-read Model|null $notifiable
 * @property-read NotificationDeliveryRetry|null $latestRetry
 * @property-read int $retries_count
 */
#[Fillable([
    'event_key',
    'company_id',
    'work_request_id',
    'weekly_progress_report_id',
    'notification_type',
    'notifiable_type',
    'notifiable_id',
    'channel',
    'delivery_data',
])]
class NotificationDelivery extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'work_request_id' => 'integer',
            'weekly_progress_report_id' => 'integer',
            'notifiable_id' => 'integer',
            'notification_type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'delivery_data' => 'array',
            'status' => NotificationDeliveryStatus::class,
            'attempt_count' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'failure_code' => NotificationDeliveryFailure::class,
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<WeeklyProgressReport, $this> */
    public function weeklyProgressReport(): BelongsTo
    {
        return $this->belongsTo(WeeklyProgressReport::class);
    }

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<NotificationDeliveryRetry, $this> */
    public function retries(): HasMany
    {
        return $this->hasMany(NotificationDeliveryRetry::class);
    }

    /** @return HasOne<NotificationDeliveryRetry, $this> */
    public function latestRetry(): HasOne
    {
        return $this->hasOne(NotificationDeliveryRetry::class)->latestOfMany('requested_at');
    }
}
