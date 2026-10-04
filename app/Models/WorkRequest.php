<?php

namespace App\Models;

use App\Enums\IntakeChannel;
use App\Enums\MajorIncidentEventType;
use App\Enums\MajorIncidentResponseStatus;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Models\Concerns\HasCompanyVisibility;
use App\Observers\WorkRequestObserver;
use Carbon\CarbonInterface;
use Database\Factories\WorkRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property-read Collection<int, MajorIncidentEvent> $majorIncidentEvents
 * @property-read MajorIncidentEvent|null $firstResponseEvent
 * @property-read Collection<int, MajorIncidentRollback> $majorIncidentRollbacks
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
    public const MAJOR_INCIDENT_FIRST_RESPONSE_TARGET_MINUTES = 60;

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

    public function isMajorIncident(): bool
    {
        return $this->is_urgent && ! $this->status->isTerminal();
    }

    public function majorIncidentFirstResponseTargetAt(): ?CarbonInterface
    {
        if (! $this->is_urgent) {
            return null;
        }

        return $this->requested_at->copy()->addMinutes(self::MAJOR_INCIDENT_FIRST_RESPONSE_TARGET_MINUTES);
    }

    public function majorIncidentResponseTargetIsContractual(): bool
    {
        return false;
    }

    public function hasMajorIncidentFirstResponseTargetElapsed(?CarbonInterface $at = null): bool
    {
        return $this->majorIncidentFirstResponseTargetStatus($at) === MajorIncidentResponseStatus::Overdue;
    }

    public function majorIncidentFirstResponseTargetStatus(?CarbonInterface $at = null): ?MajorIncidentResponseStatus
    {
        $targetAt = $this->majorIncidentFirstResponseTargetAt();
        if ($targetAt === null) {
            return null;
        }

        $response = $this->relationLoaded('firstResponseEvent')
            ? $this->getRelation('firstResponseEvent')
            : $this->firstResponseEvent()->first();

        if ($response instanceof MajorIncidentEvent) {
            return $response->occurred_at->isAfter($targetAt)
                ? MajorIncidentResponseStatus::Late
                : MajorIncidentResponseStatus::Met;
        }

        if ($this->status->isTerminal()) {
            return MajorIncidentResponseStatus::Unrecorded;
        }

        return ($at ?? now())->isAfter($targetAt)
            ? MajorIncidentResponseStatus::Overdue
            : MajorIncidentResponseStatus::Pending;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMajorIncidents(Builder $query): Builder
    {
        return $query
            ->where('is_urgent', true)
            ->whereNotIn('status', [
                WorkRequestStatus::Completed->value,
                WorkRequestStatus::Cancelled->value,
            ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMajorIncidentsFirst(Builder $query): Builder
    {
        return $query->orderByRaw(
            'CASE WHEN work_requests.is_urgent = ? AND work_requests.status NOT IN (?, ?) THEN 0 ELSE 1 END',
            [true, WorkRequestStatus::Completed->value, WorkRequestStatus::Cancelled->value],
        );
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

    /** @return HasMany<WorkLog, $this> */
    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<WorkRequestActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(WorkRequestActivity::class);
    }

    /** @return HasMany<EstimateVersion, $this> */
    public function estimateVersions(): HasMany
    {
        return $this->hasMany(EstimateVersion::class);
    }

    /** @return HasMany<WorkRequestStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(WorkRequestStatusChange::class);
    }

    /** @return HasMany<MajorIncidentEvent, $this> */
    public function majorIncidentEvents(): HasMany
    {
        return $this->hasMany(MajorIncidentEvent::class);
    }

    /** @return HasOne<MajorIncidentEvent, $this> */
    public function firstResponseEvent(): HasOne
    {
        return $this->hasOne(MajorIncidentEvent::class)
            ->where('event_type', MajorIncidentEventType::FirstResponse->value);
    }

    /** @return HasMany<MajorIncidentRollback, $this> */
    public function majorIncidentRollbacks(): HasMany
    {
        return $this->hasMany(MajorIncidentRollback::class);
    }

    /** @return HasOne<EstimateVersion, $this> */
    public function latestEstimateVersion(): HasOne
    {
        return $this->hasOne(EstimateVersion::class)->ofMany('version', 'max');
    }

    /** @return BelongsTo<EstimateVersion, $this> */
    public function approvedEstimateVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateVersion::class, 'approved_estimate_version_id');
    }
}
