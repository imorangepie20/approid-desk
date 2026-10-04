<?php

namespace App\Actions;

use App\Enums\AttachmentScanStatus;
use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use App\Services\AttachmentFileTypeRules;
use App\Services\AttachmentLimits;
use App\Services\AttachmentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

class RegisterAttachment
{
    public function __construct(private ?AttachmentStorage $storage = null) {}

    public function handle(User $actor, WorkRequest|WorkRequestComment|EstimateVersion $target, UploadedFile $file): Attachment
    {
        $storage = $this->storage ??= new AttachmentStorage;
        $stored = null;

        try {
            $attachment = DB::transaction(function () use ($actor, $target, $file, $storage, &$stored): Attachment {
                $attachment = (new CreateAttachmentLink)->handle($actor, $target);
                if (! $file->isValid()) {
                    throw ValidationException::withMessages(['file' => '정상적으로 업로드된 파일이 필요합니다.']);
                }
                (new AttachmentLimits)->validateSize($file->getSize());
                $contentSha256 = hash_file('sha256', $file->getPathname());
                $data = Validator::make([
                    'original_name' => $file->getClientOriginalName(),
                    'size_bytes' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'content_sha256' => $contentSha256,
                ], [
                    'original_name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F\\\\\/]/u', 'not_in:.,..'],
                    'size_bytes' => ['required', 'integer', 'min:0'],
                    'mime_type' => ['required', 'string', 'max:127', 'regex:~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~'],
                    'content_sha256' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/D'],
                ])->validate();
                (new AttachmentFileTypeRules)->validate($data['original_name'], $data['mime_type']);
                $stored = $storage->store($file);
                $attachment->forceFill([...$data, 'storage_disk' => $stored['disk'], 'storage_path' => $stored['path'],
                    'uploaded_by' => $actor->id, 'uploaded_at' => now(), 'scan_status' => AttachmentScanStatus::Pending,
                    'scanned_at' => null])->save();

                return $attachment;
            });
            ScanAttachment::dispatch($attachment->id)->afterCommit();

            return $attachment;
        } catch (Throwable $exception) {
            if ($stored !== null) {
                $storage->delete($stored['disk'], $stored['path']);
            }

            throw $exception;
        }
    }
}
