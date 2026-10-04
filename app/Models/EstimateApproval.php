<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasCompanyVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int $estimate_version_id
 * @property int $approved_by
 * @property UserRole $approver_role
 * @property CarbonInterface $approved_at
 * @property string $idempotency_key
 * @property string $approval_text
 * @property string $ip_address
 * @property string $user_agent
 */
#[Hidden(['ip_address', 'user_agent', 'idempotency_key'])]
class EstimateApproval extends Model
{
    use HasCompanyVisibility;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'approver_role' => UserRole::class,
            'approved_at' => 'datetime',
            'ip_address' => 'encrypted',
            'user_agent' => 'encrypted',
        ];
    }

    /** @return BelongsTo<EstimateVersion, $this> */
    public function estimateVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
