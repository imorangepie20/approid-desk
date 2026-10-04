<?php

namespace App\Services;

use App\Enums\NotificationAudience;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

class NotificationRecipients
{
    /** @return Collection<int, User> */
    public function for(
        NotificationType $type,
        WorkRequest $request,
        ?User $actor = null,
        ?Attachment $attachment = null,
    ): Collection {
        $request = WorkRequest::query()->findOrFail($request->id);

        if ($attachment !== null
            && ($attachment->work_request_id !== $request->id || $attachment->company_id !== $request->company_id)) {
            throw new LogicException('알림 첨부파일은 요청과 같은 고객사 및 요청에 속해야 합니다.');
        }

        $ids = collect();

        foreach ($type->audiences() as $audience) {
            $audienceIds = match ($audience) {
                NotificationAudience::Operations => User::query()
                    ->whereIn('role', [UserRole::SuperAdmin->value, UserRole::Operator->value])
                    ->pluck('id'),
                NotificationAudience::CompanyAdmins => User::query()
                    ->where('company_id', $request->company_id)
                    ->where('role', UserRole::CustomerAdmin->value)
                    ->pluck('id'),
                NotificationAudience::RequestSubmitter => collect([$request->submitted_by]),
                NotificationAudience::RequestAssignee => collect([$request->assigned_to]),
                NotificationAudience::RequestStakeholders => collect([
                    $request->submitted_by,
                    $request->assigned_to,
                    ...$request->comments()->pluck('author_id')->all(),
                ]),
                NotificationAudience::AttachmentUploader => collect([$attachment?->uploaded_by]),
            };

            $ids = $ids->merge($audienceIds);
        }

        $ids = $ids
            ->filter(fn (mixed $id): bool => is_int($id) || is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->when($actor !== null, fn ($ids) => $ids->reject(fn (int $id): bool => $id === $actor->id))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return User::query()
            ->whereKey($ids->all())
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(function (User $user) use ($request, $attachment): bool {
                if (! $user->canAccessWorkspace()
                    || ! WorkRequest::query()->visibleTo($user)->whereKey($request->id)->exists()) {
                    return false;
                }

                return $attachment === null
                    || Attachment::query()->visibleTo($user)->whereKey($attachment->id)->exists();
            })
            ->values();
    }
}
