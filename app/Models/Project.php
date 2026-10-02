<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property ProjectStatus $status
 * @property string|null $site_url
 * @property string|null $technical_notes
 * @property bool $is_existing_site
 * @property bool $source_code_secured
 * @property bool $database_dump_secured
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Company $company
 * @property-read Collection<int, ProjectSecret> $secrets
 * @property-read Collection<int, WorkRequest> $workRequests
 */
#[Fillable([
    'company_id',
    'name',
    'description',
    'status',
    'site_url',
    'technical_notes',
    'is_existing_site',
    'source_code_secured',
    'database_dump_secured',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasCompanyVisibility, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'is_existing_site' => 'boolean',
            'source_code_secured' => 'boolean',
            'database_dump_secured' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<ProjectSecret, $this>
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(ProjectSecret::class);
    }

    /** @return HasMany<WorkRequest, $this> */
    public function workRequests(): HasMany
    {
        return $this->hasMany(WorkRequest::class);
    }
}
