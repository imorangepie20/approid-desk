<?php

namespace App\Models;

use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestType;
use Carbon\CarbonInterface;
use Database\Factories\PricingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property WorkRequestType $work_type
 * @property WorkDifficulty $difficulty
 * @property int $hourly_rate
 * @property int $urgent_surcharge_bps
 * @property string $urgent_criteria
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_until
 */
#[Fillable(['work_type', 'difficulty', 'hourly_rate', 'urgent_surcharge_bps', 'urgent_criteria', 'valid_from', 'valid_until'])]
class PricingRule extends Model
{
    /** @use HasFactory<PricingRuleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_type' => WorkRequestType::class,
            'difficulty' => WorkDifficulty::class,
            'hourly_rate' => 'integer',
            'urgent_surcharge_bps' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }
}
