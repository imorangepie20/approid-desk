<?php

namespace App\Models;

use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int $pricing_assessment_id
 * @property int $version
 * @property string $included_scope
 * @property string $excluded_scope
 * @property int $estimated_minutes
 * @property CarbonInterface $scheduled_on
 * @property CarbonInterface $usage_month
 * @property int $amount
 * @property array<string, mixed> $rate_snapshot
 * @property string $pricing_rationale
 * @property int $created_by
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface $created_at
 * @property-read EstimateApproval|null $approval
 */
class EstimateVersion extends Model
{
    use HasCompanyVisibility {
        scopeVisibleTo as private scopeCompanyVisibleTo;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query = $this->scopeCompanyVisibleTo($query, $user);

        return $user->role->isSystemRole() ? $query : $query->whereNotNull('submitted_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'estimated_minutes' => 'integer',
            'amount' => 'integer',
            'scheduled_on' => 'date',
            'usage_month' => 'date',
            'rate_snapshot' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<PricingAssessment, $this> */
    public function pricingAssessment(): BelongsTo
    {
        return $this->belongsTo(PricingAssessment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<EstimateApproval, $this> */
    public function approval(): HasOne
    {
        return $this->hasOne(EstimateApproval::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
