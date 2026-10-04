<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Models\Concerns\HasCompanyVisibility;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property CompanyStatus $status
 * @property string|null $primary_contact_name
 * @property string|null $primary_contact_email
 * @property string|null $primary_contact_phone
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, UserInvitation> $userInvitations
 * @property-read Collection<int, WorkRequest> $workRequests
 * @property-read Collection<int, WorkRequestComment> $workRequestComments
 * @property-read Collection<int, WorkRequestActivity> $workRequestActivities
 * @property-read Collection<int, WeeklyProgressReport> $weeklyProgressReports
 */
#[Fillable([
    'name',
    'status',
    'primary_contact_name',
    'primary_contact_email',
    'primary_contact_phone',
    'notes',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasCompanyVisibility, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CompanyStatus::Active->value);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<UserInvitation, $this> */
    public function userInvitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    /** @return HasMany<WorkRequest, $this> */
    public function workRequests(): HasMany
    {
        return $this->hasMany(WorkRequest::class);
    }

    /** @return HasMany<ServiceContract, $this> */
    public function serviceContracts(): HasMany
    {
        return $this->hasMany(ServiceContract::class);
    }

    /** @return HasMany<WorkRequestComment, $this> */
    public function workRequestComments(): HasMany
    {
        return $this->hasMany(WorkRequestComment::class);
    }

    /** @return HasMany<WorkRequestActivity, $this> */
    public function workRequestActivities(): HasMany
    {
        return $this->hasMany(WorkRequestActivity::class);
    }

    /** @return HasMany<WeeklyProgressReport, $this> */
    public function weeklyProgressReports(): HasMany
    {
        return $this->hasMany(WeeklyProgressReport::class);
    }

    protected function companyVisibilityColumn(): string
    {
        return 'id';
    }
}
