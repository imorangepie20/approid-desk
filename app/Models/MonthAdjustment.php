<?php

namespace App\Models;

use App\Enums\TimeLedgerType;
use App\Models\Concerns\HasCompanyVisibility;
use App\Models\Concerns\ImmutableAuditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $company_id
 * @property int $contract_month_id
 * @property int $related_entry_id
 * @property TimeLedgerType $type
 * @property int $minutes
 * @property int $approved_by
 * @property string $reason
 */
class MonthAdjustment extends Model
{
    use HasCompanyVisibility, ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => TimeLedgerType::class, 'minutes' => 'integer', 'approved_at' => 'datetime'];
    }
}
