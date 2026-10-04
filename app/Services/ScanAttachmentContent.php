<?php

namespace App\Services;

use App\Contracts\MalwareScanner;
use App\Enums\AttachmentScanFailure;
use App\Exceptions\AttachmentScanException;
use App\Models\Attachment;
use App\ValueObjects\MalwareScanResult;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ScanAttachmentContent
{
    public function __construct(private MalwareScanner $scanner) {}

    public function handle(Attachment $attachment): MalwareScanResult
    {
        if (config('attachments.scanner.driver') !== 'clamav') {
            throw new AttachmentScanException(AttachmentScanFailure::ScannerUnavailable, 'Malware scanner is not configured.');
        }
        if ($attachment->storage_disk === null || $attachment->storage_path === null
            || $attachment->size_bytes === null
            || ! preg_match('~^objects/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$~D', $attachment->storage_path)) {
            throw new AttachmentScanException(AttachmentScanFailure::StorageUnavailable, 'Attachment storage is unavailable for scanning.');
        }
        $configuration = config('filesystems.disks.'.$attachment->storage_disk);
        if (! is_array($configuration) || ($configuration['visibility'] ?? null) !== 'private') {
            throw new AttachmentScanException(AttachmentScanFailure::StorageUnavailable, 'Attachment storage is not private.');
        }

        $stream = null;
        try {
            $disk = Storage::disk($attachment->storage_disk);
            if (! $this->exists($disk, $attachment->storage_path)
                || $disk->size($attachment->storage_path) !== $attachment->size_bytes) {
                throw new AttachmentScanException(AttachmentScanFailure::ContentChanged, 'Attachment content is missing or changed.');
            }
            $stream = $disk->readStream($attachment->storage_path);
            if (! is_resource($stream)) {
                throw new RuntimeException('Attachment stream could not be opened.');
            }
        } catch (AttachmentScanException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AttachmentScanException(
                AttachmentScanFailure::StorageUnavailable,
                'Attachment storage could not be read.',
                $exception,
            );
        }

        try {
            $result = $this->scanner->scan($stream, $attachment->size_bytes);
            if ($result->contentSha256 === null
                || ! preg_match('/^[0-9a-f]{64}$/D', $result->contentSha256)
                || ($attachment->content_sha256 !== null
                    && ! hash_equals($attachment->content_sha256, $result->contentSha256))) {
                throw new AttachmentScanException(AttachmentScanFailure::ContentChanged, 'Attachment content digest changed.');
            }

            return $result;
        } catch (AttachmentScanException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AttachmentScanException(
                AttachmentScanFailure::ScannerUnavailable,
                'Malware scanner failed.',
                $exception,
            );
        } finally {
            fclose($stream);
        }
    }

    /** @phpstan-impure */
    private function exists(FilesystemAdapter $disk, string $path): bool
    {
        return $disk->exists($path);
    }
}
