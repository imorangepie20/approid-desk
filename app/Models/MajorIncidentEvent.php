<?php

namespace App\Models;

use App\Enums\MajorIncidentEventType;
use App\Models\Concerns\HasCompanyVisibility;
use App\Models\Concerns\ImmutableAuditRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int $recorded_by
 * @property MajorIncidentEventType $event_type
 * @property string $summary
 * @property string $details
 * @property CarbonInterface $occurred_at
 * @property-read WorkRequest $workRequest
 * @property-read User $recorder
 */
#[Fillable([
    'company_id',
    'work_request_id',
    'recorded_by',
    'event_type',
    'summary',
    'details',
    'occurred_at',
])]
class MajorIncidentEvent extends Model
{
    use HasCompanyVisibility, ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => MajorIncidentEventType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
