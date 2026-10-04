<?php

namespace App\Models;

use App\Enums\WorkRequestStatus;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int $changed_by
 * @property WorkRequestStatus $from_status
 * @property WorkRequestStatus $to_status
 * @property string|null $reason
 * @property int|null $estimate_version_id
 * @property bool $is_free_rework
 * @property CarbonInterface $occurred_at
 */
class WorkRequestStatusChange extends Model
{
    use HasCompanyVisibility;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => WorkRequestStatus::class,
            'to_status' => WorkRequestStatus::class,
            'is_free_rework' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
