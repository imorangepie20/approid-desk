<?php

namespace App\Models;

use App\Enums\ServiceContractStatus;
use App\Enums\ServiceContractType;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Database\Factories\ServiceContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property ServiceContractType $type
 * @property ServiceContractStatus $status
 * @property CarbonInterface $starts_on
 * @property CarbonInterface|null $ends_on
 * @property string|null $document_path
 * @property CarbonInterface|null $signature_confirmed_at
 * @property int|null $signature_confirmed_by
 * @property-read Company $company
 * @property-read User|null $signatureConfirmer
 */
#[Fillable(['company_id', 'type', 'starts_on', 'ends_on', 'status'])]
class ServiceContract extends Model
{
    /** @use HasFactory<ServiceContractFactory> */
    use HasCompanyVisibility, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ServiceContractType::class,
            'status' => ServiceContractStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'signature_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function signatureConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signature_confirmed_by');
    }

    /** @return HasMany<WorkRequest, $this> */
    public function workRequests(): HasMany
    {
        return $this->hasMany(WorkRequest::class);
    }

    public function permitsWork(): bool
    {
        return $this->status === ServiceContractStatus::Active
            && $this->signature_confirmed_at !== null
            && ! $this->signature_confirmed_at->isFuture()
            && $this->signature_confirmed_by !== null
            && filled($this->document_path)
            && $this->starts_on->lte(today())
            && ($this->ends_on === null || $this->ends_on->gte(today()));
    }
}
