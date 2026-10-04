<?php

namespace App\Models;

use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $service_contract_id
 * @property CarbonInterface $month
 * @property int $provided_minutes
 * @property string $status
 * @property CarbonInterface|null $closed_at
 * @property int|null $closed_by
 * @property-read ServiceContract $serviceContract
 */
class ContractMonth extends Model
{
    use HasCompanyVisibility;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['month' => 'date', 'provided_minutes' => 'integer', 'closed_at' => 'datetime'];
    }

    /** @return HasMany<TimeLedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TimeLedgerEntry::class);
    }

    /** @return BelongsTo<ServiceContract, $this> */
    public function serviceContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class);
    }
}
