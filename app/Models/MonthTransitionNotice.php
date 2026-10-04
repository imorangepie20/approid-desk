<?php

namespace App\Models;

use App\Models\Concerns\HasCompanyVisibility;
use App\Models\Concerns\ImmutableAuditRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $contract_month_id
 * @property int $work_request_id
 * @property int $reserve_entry_id
 * @property int $remaining_minutes
 * @property CarbonInterface $detected_for
 * @property int $recipient_count
 * @property CarbonInterface $notified_at
 */
class MonthTransitionNotice extends Model
{
    use HasCompanyVisibility, ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'remaining_minutes' => 'integer',
            'recipient_count' => 'integer',
            'detected_for' => 'date',
            'notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ContractMonth, $this> */
    public function contractMonth(): BelongsTo
    {
        return $this->belongsTo(ContractMonth::class);
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }
}
