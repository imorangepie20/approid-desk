<?php

namespace App\Models;

use App\Enums\WorkLogStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int $worker_id
 * @property CarbonInterface $worked_on
 * @property string $description
 * @property int $minutes
 * @property bool $is_billable
 * @property string|null $non_billable_reason
 * @property WorkLogStatus $status
 * @property CarbonInterface|null $confirmed_at
 * @property int|null $confirmed_by
 * @property int $revision
 */
class WorkLog extends Model
{
    protected static function booted(): void
    {
        static::saving(function (WorkLog $log): void {
            if ($log->status !== WorkLogStatus::Draft || $log->confirmed_at !== null || $log->confirmed_by !== null
                || ($log->exists && ($log->getRawOriginal('status') === WorkLogStatus::Confirmed->value
                    || $log->isDirty(['company_id', 'work_request_id', 'worker_id'])))) {
                throw new LogicException('작업기록 확정은 원장 반영 기능에서만 처리할 수 있으며 식별 정보와 확정 기록은 변경할 수 없습니다.');
            }
        });
        static::deleting(function (WorkLog $log): void {
            if ($log->getRawOriginal('status') === WorkLogStatus::Confirmed->value) {
                throw new LogicException('확정 작업기록은 삭제할 수 없습니다.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['worked_on' => 'date', 'minutes' => 'integer', 'is_billable' => 'boolean',
            'status' => WorkLogStatus::class, 'confirmed_at' => 'datetime', 'revision' => 'integer'];
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->canAccessWorkspace()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->role->isSystemRole()) {
            return $query;
        }

        return $query->where('company_id', $user->company_id)->where('status', WorkLogStatus::Confirmed->value);
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
