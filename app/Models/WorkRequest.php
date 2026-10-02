<?php

namespace App\Models;

use App\Enums\IntakeChannel;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Models\Concerns\HasCompanyVisibility;
use App\Observers\WorkRequestObserver;
use Carbon\CarbonInterface;
use Database\Factories\WorkRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property int|null $service_contract_id
 * @property int $submitted_by
 * @property int|null $assigned_to
 * @property int|null $parent_request_id
 * @property string $title
 * @property string $requirements
 * @property WorkRequestType $type
 * @property WorkRequestPriority $priority
 * @property bool $is_urgent
 * @property CarbonInterface|null $desired_due_date
 * @property IntakeChannel $intake_channel
 * @property string|null $source_reference
 * @property string|null $intake_summary
 * @property WorkRequestStatus $status
 * @property CarbonInterface $requested_at
 * @property CarbonInterface $registered_at
 * @property string|null $late_entry_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Company $company
 * @property-read Project $project
 * @property-read User $submitter
 * @property-read User|null $assignee
 * @property-read WorkRequest|null $parentRequest
 * @property-read Collection<int, WorkRequest> $additionalRequests
 * @property-read Collection<int, WorkRequestComment> $comments
 * @property-read Collection<int, WorkRequestActivity> $activities
 */
#[Fillable([
    'company_id',
    'project_id',
    'service_contract_id',
    'submitted_by',
    'assigned_to',
    'parent_request_id',
    'title',
    'requirements',
    'type',
    'priority',
    'is_urgent',
    'desired_due_date',
    'intake_channel',
    'source_reference',
    'intake_summary',
    'status',
    'requested_at',
    'registered_at',
    'late_entry_reason',
])]
#[ObservedBy([WorkRequestObserver::class])]
class WorkRequest extends Model
{
    /** @use HasFactory<WorkRequestFactory> */
    use HasCompanyVisibility, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WorkRequestType::class,
            'priority' => WorkRequestPriority::class,
            'is_urgent' => 'boolean',
            'desired_due_date' => 'date',
            'intake_channel' => IntakeChannel::class,
            'status' => WorkRequestStatus::class,
            'requested_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ServiceContract, $this> */
    public function serviceContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<WorkRequest, $this> */
    public function parentRequest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_request_id');
    }

    /** @return HasMany<WorkRequest, $this> */
    public function additionalRequests(): HasMany
    {
        return $this->hasMany(self::class, 'parent_request_id');
    }

    /** @return HasMany<WorkRequestComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(WorkRequestComment::class);
    }

    /** @return HasMany<WorkRequestActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(WorkRequestActivity::class);
    }
}
