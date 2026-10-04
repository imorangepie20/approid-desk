<?php

namespace App\Actions;

use App\Models\Attachment;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use App\Services\AttachmentLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateAttachmentLink
{
    public function handle(User $actor, WorkRequest|WorkRequestComment|EstimateVersion $target): Attachment
    {
        return DB::transaction(function () use ($actor, $target): Attachment {
            $target = $target->newQuery()->findOrFail($target->id);
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($target instanceof WorkRequest ? $target->id : $target->work_request_id);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $target = $target->newQuery()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('create', [Attachment::class, $target]);
            (new AttachmentLimits)->validateCount($request);
            $attachment = (new Attachment)->forceFill([
                'company_id' => $request->company_id, 'work_request_id' => $request->id,
                'work_request_comment_id' => $target instanceof WorkRequestComment ? $target->id : null,
                'estimate_version_id' => $target instanceof EstimateVersion ? $target->id : null,
            ]);
            $attachment->save();

            return $attachment;
        }, 5);
    }
}
