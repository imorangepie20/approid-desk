<?php

namespace App\Services;

use App\Enums\AttachmentDeletionFailure;
use App\Models\Attachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DeleteAttachmentContent
{
    public function handle(Attachment $attachment): ?AttachmentDeletionFailure
    {
        if ($attachment->storage_disk === null && $attachment->storage_path === null) {
            return null;
        }
        $configuration = is_string($attachment->storage_disk)
            ? config('filesystems.disks.'.$attachment->storage_disk) : null;
        if (! is_array($configuration) || ($configuration['visibility'] ?? null) !== 'private'
            || ! is_string($attachment->storage_path)
            || ! preg_match('~^objects/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$~D', $attachment->storage_path)) {
            report(new RuntimeException('Attachment storage cannot be safely deleted for attachment '.$attachment->id.'.'));

            return AttachmentDeletionFailure::StorageUnavailable;
        }

        try {
            $disk = Storage::disk($attachment->storage_disk);
            if (! $this->exists($disk, $attachment->storage_path)) {
                return null;
            }
            if (! $disk->delete($attachment->storage_path) || $this->exists($disk, $attachment->storage_path)) {
                return AttachmentDeletionFailure::StorageDeleteFailed;
            }
        } catch (Throwable $exception) {
            report($exception);

            return AttachmentDeletionFailure::StorageUnavailable;
        }

        return null;
    }

    /** @phpstan-impure */
    private function exists(FilesystemAdapter $disk, string $path): bool
    {
        return $disk->exists($path);
    }
}
