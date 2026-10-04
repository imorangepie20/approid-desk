<?php

namespace App\Models;

use App\Models\Concerns\HasCompanyVisibility;
use App\Observers\WorkRequestCommentObserver;
use Carbon\CarbonInterface;
use Database\Factories\WorkRequestCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $work_request_id
 * @property int $author_id
 * @property string $body
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Company $company
 * @property-read WorkRequest $workRequest
 * @property-read User $author
 */
#[Fillable(['company_id', 'work_request_id', 'author_id', 'body'])]
#[ObservedBy([WorkRequestCommentObserver::class])]
class WorkRequestComment extends Model
{
    /** @use HasFactory<WorkRequestCommentFactory> */
    use HasCompanyVisibility, HasFactory;

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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
