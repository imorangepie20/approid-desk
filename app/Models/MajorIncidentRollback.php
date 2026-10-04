<?php

namespace App\Models;

use App\Enums\MajorIncidentRollbackOutcome;
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
 * @property int $started_by
 * @property string $target
 * @property string $plan
 * @property string $verification_plan
 * @property CarbonInterface $started_at
 * @property MajorIncidentRollbackOutcome|null $outcome
 * @property string|null $result_summary
 * @property string|null $result_details
 * @property int|null $completed_by
 * @property CarbonInterface|null $completed_at
 * @property-read WorkRequest $workRequest
 * @property-read User $starter
 * @property-read User|null $completer
 */
#[Fillable([
    'company_id',
    'work_request_id',
    'started_by',
    'target',
    'plan',
    'verification_plan',
    'started_at',
])]
class MajorIncidentRollback extends Model
{
    use HasCompanyVisibility, ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'outcome' => MajorIncidentRollbackOutcome::class,
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->outcome === null;
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
