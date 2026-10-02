<?php

namespace App\Models;

use App\Enums\WorkRequestActivityType;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Database\Factories\WorkRequestActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int|null $actor_id
 * @property int|null $comment_id
 * @property WorkRequestActivityType $type
 * @property string $summary
 * @property array<string, mixed>|null $before_values
 * @property array<string, mixed>|null $after_values
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Company $company
 * @property-read WorkRequest $workRequest
 * @property-read User|null $actor
 * @property-read WorkRequestComment|null $comment
 */
#[Fillable([
    'company_id',
    'work_request_id',
    'actor_id',
    'comment_id',
    'type',
    'summary',
    'before_values',
    'after_values',
    'occurred_at',
])]
class WorkRequestActivity extends Model
{
    /** @use HasFactory<WorkRequestActivityFactory> */
    use HasCompanyVisibility, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WorkRequestActivityType::class,
            'before_values' => 'array',
            'after_values' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    /** @return BelongsTo<WorkRequestComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(WorkRequestComment::class, 'comment_id');
    }
}
