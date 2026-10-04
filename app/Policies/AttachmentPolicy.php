<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Attachment;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use App\Policies\Concerns\ChecksCompanyScope;
use Illuminate\Support\Facades\Gate;

class AttachmentPolicy
{
    use ChecksCompanyScope;

    public function view(User $user, Attachment $attachment): bool
    {
        return $this->canAccessCompany($user, $attachment->company_id)
            && ($attachment->estimate_version_id === null
                || Gate::forUser($user)->allows('view', $attachment->estimateVersion));
    }

    public function create(User $user, WorkRequest|WorkRequestComment|EstimateVersion $target): bool
    {
        if ($target instanceof EstimateVersion) {
            return $target->submitted_at === null
                && Gate::forUser($user)->allows('writeDraft', [EstimateVersion::class, $target->workRequest]);
        }

        return $this->hasCompanyPermission($user, Permission::CommentOnRequests, $target->company_id)
            && (! $target instanceof WorkRequestComment || $target->author_id === $user->id);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        if (! $this->canAccessCompany($user, $attachment->company_id)) {
            return false;
        }
        if ($attachment->estimate_version_id !== null) {
            return $attachment->estimateVersion?->submitted_at === null
                && Gate::forUser($user)->allows('writeDraft', [EstimateVersion::class, $attachment->workRequest]);
        }

        return $user->role->isSystemRole() || $attachment->uploaded_by === $user->id;
    }
}
