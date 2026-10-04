<?php

namespace App\Models;

use App\Enums\TimeLedgerType;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $contract_month_id
 * @property int|null $work_request_id
 * @property TimeLedgerType $type
 * @property int $minutes
 * @property string $source_type
 * @property int $source_id
 * @property int $actor_id
 * @property string $reason
 * @property CarbonInterface $occurred_at
 */
class TimeLedgerEntry extends Model
{
    use HasCompanyVisibility;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('시간 원장은 수정할 수 없습니다.'));
        static::deleting(fn () => throw new LogicException('시간 원장은 삭제할 수 없습니다.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => TimeLedgerType::class, 'minutes' => 'integer', 'occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
