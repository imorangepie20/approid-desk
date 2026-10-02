<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $company_id
 * @property UserRole $role
 * @property bool $is_active
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, WorkRequest> $submittedWorkRequests
 * @property-read Collection<int, WorkRequest> $assignedWorkRequests
 * @property-read Collection<int, WorkRequestComment> $workRequestComments
 * @property-read Collection<int, WorkRequestActivity> $workRequestActivities
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasCompanyVisibility, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<WorkRequest, $this> */
    public function submittedWorkRequests(): HasMany
    {
        return $this->hasMany(WorkRequest::class, 'submitted_by');
    }

    /** @return HasMany<WorkRequest, $this> */
    public function assignedWorkRequests(): HasMany
    {
        return $this->hasMany(WorkRequest::class, 'assigned_to');
    }

    /** @return HasMany<WorkRequestComment, $this> */
    public function workRequestComments(): HasMany
    {
        return $this->hasMany(WorkRequestComment::class, 'author_id');
    }

    /** @return HasMany<WorkRequestActivity, $this> */
    public function workRequestActivities(): HasMany
    {
        return $this->hasMany(WorkRequestActivity::class, 'actor_id');
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function canAccessWorkspace(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->role->isSystemRole()) {
            return true;
        }

        return $this->company_id !== null
            && $this->company()->where('status', CompanyStatus::Active->value)->exists();
    }
}
