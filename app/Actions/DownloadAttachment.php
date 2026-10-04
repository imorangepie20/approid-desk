<?php

namespace App\Actions;

use App\Enums\AttachmentDeletionStatus;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DownloadAttachment
{
    public function handle(Attachment $attachment): StreamedResponse
    {
        abort_if($attachment->deletion_status === AttachmentDeletionStatus::Deleted, 410, '삭제된 첨부파일입니다.');
        abort_if($attachment->deletion_status !== AttachmentDeletionStatus::Active, 409, '삭제 처리 중인 첨부파일입니다.');
        abort_if($attachment->scan_status !== AttachmentScanStatus::Clean, 409, '안전 검사가 완료된 첨부파일만 다운로드할 수 있습니다.');
        abort_if($attachment->storage_disk === null || $attachment->storage_path === null
            || $attachment->original_name === null, 404);
        if (strlen($attachment->original_name) > 255
            || preg_match('/[\x00-\x1F\x7F\\\\\/]/u', $attachment->original_name)) {
            report(new RuntimeException('Attachment download name is invalid for attachment '.$attachment->id.'.'));
            abort(503, '첨부파일을 현재 다운로드할 수 없습니다.');
        }

        if (! preg_match('~^objects/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$~D', $attachment->storage_path)) {
            report(new RuntimeException('Attachment stored content is unavailable for attachment '.$attachment->id.'.'));
            abort(503, '첨부파일을 현재 다운로드할 수 없습니다.');
        }

        try {
            $disk = $this->privateDisk($attachment);
            if (! $disk->exists($attachment->storage_path)
                || $attachment->size_bytes === null || $disk->size($attachment->storage_path) !== $attachment->size_bytes) {
                throw new RuntimeException('Stored attachment is missing or has an unexpected size.');
            }
            if ($attachment->content_sha256 !== null
                && ! hash_equals($attachment->content_sha256, $this->sha256($disk, $attachment->storage_path))) {
                throw new RuntimeException('Stored attachment content digest does not match.');
            }

            return $disk->download($attachment->storage_path, $attachment->original_name, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $attachment->original_name,
                    $this->fallbackName($attachment),
                ),
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $exception) {
            report($exception);
            abort(503, '첨부파일을 현재 다운로드할 수 없습니다.');
        }
    }

    private function fallbackName(Attachment $attachment): string
    {
        $extension = strtolower(pathinfo($attachment->original_name, PATHINFO_EXTENSION));
        if (! preg_match('/^[a-z0-9]{1,10}$/D', $extension)) {
            $extension = '';
        }

        return 'attachment-'.$attachment->id.($extension === '' ? '' : '.'.$extension);
    }

    private function sha256(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Stored attachment could not be read.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function privateDisk(Attachment $attachment): FilesystemAdapter
    {
        $configuration = config('filesystems.disks.'.$attachment->storage_disk);
        if (! is_array($configuration) || ($configuration['visibility'] ?? null) !== 'private') {
            throw new RuntimeException('Attachment private disk is unavailable for attachment '.$attachment->id.'.');
        }

        return Storage::disk($attachment->storage_disk);
    }
}
