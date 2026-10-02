<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Database\Factories\UserInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $invited_by
 * @property string $email
 * @property UserRole $role
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property int|null $accepted_user_id
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'company_id',
    'invited_by',
    'email',
    'role',
    'token_hash',
    'expires_at',
    'accepted_at',
    'accepted_user_id',
    'revoked_at',
])]
#[Hidden(['token_hash'])]
class UserInvitation extends Model
{
    /** @use HasFactory<UserInvitationFactory> */
    use HasCompanyVisibility, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<UserInvitation>  $query
     * @return Builder<UserInvitation>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('accepted_user_id')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereHas('company', fn (Builder $company): Builder => $company->where('status', CompanyStatus::Active->value));
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }
}
