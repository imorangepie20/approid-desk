<?php

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonInterface;
use Database\Factories\ProjectSecretFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $label
 * @property array<string, mixed> $secret_data
 * @property CarbonInterface|null $last_verified_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Project $project
 */
#[Fillable([
    'project_id',
    'label',
    'secret_data',
    'last_verified_at',
])]
class ProjectSecret extends Model
{
    /** @use HasFactory<ProjectSecretFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret_data' => 'encrypted:array',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ProjectSecret>  $query
     * @return Builder<ProjectSecret>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->is_active || $user->role !== UserRole::SuperAdmin) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
