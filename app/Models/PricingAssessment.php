<?php

namespace App\Models;

use App\Enums\PricingDecision;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int $pricing_rule_id
 * @property int $estimated_minutes
 * @property bool $is_urgent
 * @property int $amount
 * @property array<string, mixed> $rate_snapshot
 * @property string $rationale
 * @property PricingDecision $decision
 * @property string|null $decision_reason
 * @property CarbonInterface $created_at
 */
#[Fillable(['company_id', 'work_request_id', 'pricing_rule_id', 'assessed_by', 'priced_on', 'estimated_minutes', 'is_urgent', 'amount', 'rate_snapshot', 'rationale', 'decision', 'decision_reason'])]
class PricingAssessment extends Model
{
    use HasCompanyVisibility;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('가격 판단 기록은 수정하지 않고 새 기록을 생성해야 합니다.');
        });
        static::deleting(function (): void {
            throw new LogicException('가격 판단 기록은 삭제할 수 없습니다.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priced_on' => 'date',
            'estimated_minutes' => 'integer',
            'is_urgent' => 'boolean',
            'amount' => 'integer',
            'rate_snapshot' => 'array',
            'decision' => PricingDecision::class,
        ];
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function workRequest(): BelongsTo
    {
        return $this->belongsTo(WorkRequest::class);
    }

    /** @return BelongsTo<PricingRule, $this> */
    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
