<?php

namespace App\Models;

use App\Models\Concerns\HasCompanyVisibility;
use App\Models\Concerns\ImmutableAuditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $company_id
 * @property int $contract_month_id
 * @property array<string, int> $totals
 * @property int $last_entry_id
 * @property int $entry_count
 */
class MonthClosure extends Model
{
    use HasCompanyVisibility, ImmutableAuditRecord;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['totals' => 'array', 'closed_at' => 'datetime', 'entry_count' => 'integer'];
    }
}
