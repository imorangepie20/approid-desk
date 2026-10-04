<?php

namespace App\Actions;

use App\Enums\AttachmentDeletionStatus;
use App\Enums\AttachmentScanEventType;
use App\Enums\AttachmentScanFailure;
use App\Enums\AttachmentScanStatus;
use App\Enums\MalwareScanVerdict;
use App\Enums\NotificationType;
use App\Exceptions\AttachmentScanException;
use App\Models\Attachment;
use App\Models\AttachmentScanEvent;
use App\Models\WorkRequest;
use App\Services\ScanAttachmentContent;
use App\ValueObjects\MalwareScanResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InspectAttachment
{
    private const STALE_AFTER_MINUTES = 5;

    public function __construct(private ScanAttachmentContent $content) {}

    public function handle(Attachment $attachment, bool $force = false): Attachment
    {
        $attemptId = null;
        $attachment = DB::transaction(function () use ($attachment, $force, &$attemptId): Attachment {
            $locator = Attachment::query()->findOrFail($attachment->id);
            WorkRequest::query()->lockForUpdate()->findOrFail($locator->work_request_id);
            $attachment = Attachment::query()->lockForUpdate()->findOrFail($locator->id);
            if ($attachment->deletion_status !== AttachmentDeletionStatus::Active
                || $attachment->scan_status === AttachmentScanStatus::Infected
                || ($attachment->scan_status === AttachmentScanStatus::Clean && ! $force)
                || $attachment->uploaded_by === null || $attachment->storage_disk === null
                || $attachment->storage_path === null || $attachment->size_bytes === null) {
                return $attachment;
            }
            if ($attachment->scan_status === AttachmentScanStatus::Scanning) {
                if ($attachment->scan_requested_at === null
                    || $attachment->scan_requested_at->isAfter(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                    throw new AttachmentScanException(
                        AttachmentScanFailure::Interrupted,
                        'Attachment scan is already in progress.',
                    );
                }
                $this->event(
                    $attachment,
                    AttachmentScanEventType::Failed,
                    $attachment->scan_attempt_id,
                    failure: AttachmentScanFailure::Interrupted,
                );
            }

            $attemptId = (string) Str::uuid();
            $attachment->forceFill([
                'scan_status' => AttachmentScanStatus::Scanning,
                'scan_attempt_id' => $attemptId,
                'scan_requested_at' => now(),
                'scanned_at' => null,
            ])->save();
            $this->event($attachment, AttachmentScanEventType::Requested, $attemptId);

            return $attachment;
        });
        if ($attemptId === null) {
            return $attachment;
        }

        $result = null;
        $failure = null;
        try {
            $result = $this->content->handle($attachment);
        } catch (AttachmentScanException $exception) {
            $failure = $exception;
        }

        $attachment = DB::transaction(function () use ($attachment, $attemptId, $result, $failure): Attachment {
            WorkRequest::query()->lockForUpdate()->findOrFail($attachment->work_request_id);
            $attachment = Attachment::query()->lockForUpdate()->findOrFail($attachment->id);
            if ($attachment->deletion_status !== AttachmentDeletionStatus::Active
                || $attachment->scan_status !== AttachmentScanStatus::Scanning
                || $attachment->scan_attempt_id !== $attemptId) {
                return $attachment;
            }
            $status = $this->status($result, $failure);
            $attachment->forceFill(['scan_status' => $status, 'scanned_at' => now(),
                'content_sha256' => $attachment->content_sha256 ?? $result?->contentSha256])->save();
            $this->event(
                $attachment,
                match ($status) {
                    AttachmentScanStatus::Clean => AttachmentScanEventType::Clean,
                    AttachmentScanStatus::Infected => AttachmentScanEventType::Infected,
                    default => AttachmentScanEventType::Failed,
                },
                $attemptId,
                $result?->signature,
                $failure?->failure,
            );

            return $attachment;
        });

        $notificationType = match ($attachment->scan_status) {
            AttachmentScanStatus::Infected => NotificationType::AttachmentInfected,
            AttachmentScanStatus::Failed => NotificationType::AttachmentScanFailed,
            default => null,
        };
        if ($notificationType !== null) {
            (new SendBusinessNotification)->handle(
                $notificationType,
                $attachment->workRequest()->firstOrFail(),
                context: [
                    'attachment_id' => $attachment->id,
                    'scan_attempt_id' => $attemptId,
                ],
                attachment: $attachment,
            );
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $attachment;
    }

    private function status(?MalwareScanResult $result, ?AttachmentScanException $failure): AttachmentScanStatus
    {
        if ($failure !== null || $result === null) {
            return AttachmentScanStatus::Failed;
        }

        return $result->verdict === MalwareScanVerdict::Clean
            ? AttachmentScanStatus::Clean
            : AttachmentScanStatus::Infected;
    }

    private function event(
        Attachment $attachment,
        AttachmentScanEventType $type,
        ?string $attemptId,
        ?string $signature = null,
        ?AttachmentScanFailure $failure = null,
    ): void {
        if ($attemptId === null) {
            throw new AttachmentScanException(AttachmentScanFailure::Interrupted, 'Attachment scan attempt is missing.');
        }
        $event = new AttachmentScanEvent;
        $event->forceFill([
            'company_id' => $attachment->company_id,
            'attachment_id' => $attachment->id,
            'attempt_id' => $attemptId,
            'event_type' => $type,
            'scanner' => 'clamav',
            'signature' => $signature,
            'failure_code' => $failure,
            'occurred_at' => now(),
        ])->save();
    }
}
