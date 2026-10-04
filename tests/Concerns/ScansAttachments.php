<?php

namespace Tests\Concerns;

use App\Actions\InspectAttachment;
use App\Enums\AttachmentScanFailure;
use App\Enums\AttachmentScanStatus;
use App\Exceptions\AttachmentScanException;
use App\Models\Attachment;
use App\Services\ScanAttachmentContent;
use Tests\Support\FakeMalwareScanner;

trait ScansAttachments
{
    protected function scanAttachmentAs(Attachment $attachment, AttachmentScanStatus $status, bool $force = false): Attachment
    {
        $scanner = match ($status) {
            AttachmentScanStatus::Clean => FakeMalwareScanner::clean(),
            AttachmentScanStatus::Infected => FakeMalwareScanner::infected(),
            AttachmentScanStatus::Failed => FakeMalwareScanner::failing(AttachmentScanFailure::ScannerUnavailable),
            default => throw new \InvalidArgumentException('A terminal scan status is required.'),
        };
        try {
            (new InspectAttachment(new ScanAttachmentContent($scanner)))->handle($attachment, $force);
        } catch (AttachmentScanException $exception) {
            if ($status !== AttachmentScanStatus::Failed) {
                throw $exception;
            }
        }

        return $attachment->fresh();
    }
}
