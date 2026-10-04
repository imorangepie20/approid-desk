<?php

namespace Tests\Feature\Models;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateEstimateVersion;
use App\Actions\DeleteAttachment;
use App\Actions\InspectAttachment;
use App\Actions\RegisterAttachment;
use App\Actions\SubmitEstimateVersion;
use App\Enums\AttachmentScanEventType;
use App\Enums\AttachmentScanFailure;
use App\Enums\AttachmentScanStatus;
use App\Enums\NotificationType;
use App\Enums\WorkDifficulty;
use App\Exceptions\AttachmentScanException;
use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\AttachmentScanEvent;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Notifications\BusinessNotification;
use App\Services\ScanAttachmentContent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Support\FakeMalwareScanner;
use Tests\TestCase;

class AttachmentScanningTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, resource> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('attachments');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            fclose($file);
        }
        parent::tearDown();
    }

    public function test_registration_queues_scan_and_clean_result_records_immutable_history(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        Queue::assertPushed(ScanAttachment::class,
            fn (ScanAttachment $job): bool => $job->attachmentId === $attachment->id);
        $scanner = FakeMalwareScanner::clean();
        $job = new ScanAttachment($attachment->id);
        $job->handle(new InspectAttachment(new ScanAttachmentContent($scanner)));
        $clean = $attachment->fresh();
        $this->assertSame(AttachmentScanStatus::Clean, $clean->scan_status);
        $this->assertNotNull($clean->scan_requested_at);
        $this->assertNotNull($clean->scanned_at);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $clean->content_sha256);
        $this->assertSame(1, $scanner->calls);
        Storage::disk('attachments')->assertExists($clean->storage_path);
        $events = $clean->scanEvents()->orderBy('id')->get();
        $this->assertSame([AttachmentScanEventType::Requested, AttachmentScanEventType::Clean],
            $events->pluck('event_type')->all());
        $this->assertSame($events[0]->attempt_id, $events[1]->attempt_id);
        $this->assertSame(2, AttachmentScanEvent::visibleTo($request->submitter)->count());
        $this->assertSame(0, AttachmentScanEvent::visibleTo(User::factory()->customerUser()->create())->count());
        $this->assertSame(2, AttachmentScanEvent::visibleTo(User::factory()->operator()->create())->count());
    }

    public function test_infected_content_is_logically_quarantined_and_never_auto_released(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        $infected = $this->inspect($attachment, FakeMalwareScanner::infected('Win.Test.Quarantine'))->fresh();
        $this->assertSame(AttachmentScanStatus::Infected, $infected->scan_status);
        $this->assertSame('Win.Test.Quarantine', $infected->scanEvents()->latest('id')->first()->signature);
        Storage::disk('attachments')->assertExists($infected->storage_path);
        $this->actingAs($request->submitter)->get(route('attachments.download', $infected))
            ->assertConflict()->assertSee('안전 검사가 완료된 첨부파일만');
        $cleanScanner = FakeMalwareScanner::clean();
        $same = $this->inspect($infected, $cleanScanner, true);
        $this->assertSame(AttachmentScanStatus::Infected, $same->scan_status);
        $this->assertSame(0, $cleanScanner->calls);
        $this->assertSame(2, $same->scanEvents()->count());
        Queue::assertPushed(SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->notification instanceof BusinessNotification
                && $job->notification->type === NotificationType::AttachmentInfected);
    }

    public function test_failed_scan_is_audited_and_retry_uses_a_new_attempt(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        try {
            $this->inspect($attachment, FakeMalwareScanner::failing(AttachmentScanFailure::ScannerUnavailable));
            $this->fail('Scanner failure accepted.');
        } catch (AttachmentScanException $exception) {
            $this->assertSame(AttachmentScanFailure::ScannerUnavailable, $exception->failure);
        }
        $failed = $attachment->fresh();
        $firstAttempt = $failed->scan_attempt_id;
        $this->assertSame(AttachmentScanStatus::Failed, $failed->scan_status);
        $this->assertSame(AttachmentScanFailure::ScannerUnavailable,
            $failed->scanEvents()->latest('id')->first()->failure_code);
        Queue::assertPushed(SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->notification instanceof BusinessNotification
                && $job->notification->type === NotificationType::AttachmentScanFailed);
        $clean = $this->inspect($failed, FakeMalwareScanner::clean())->fresh();
        $this->assertSame(AttachmentScanStatus::Clean, $clean->scan_status);
        $this->assertNotSame($firstAttempt, $clean->scan_attempt_id);
        $this->assertSame(4, $clean->scanEvents()->count());
        $this->assertSame(2, $clean->scanEvents()->distinct()->count('attempt_id'));
    }

    public function test_recent_scan_blocks_duplicate_and_deletion_while_stale_scan_recovers(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        $attempt = (string) Str::uuid();
        $attachment->forceFill(['scan_status' => AttachmentScanStatus::Scanning,
            'scan_attempt_id' => $attempt, 'scan_requested_at' => now()])->save();
        $this->event($attachment, $attempt, AttachmentScanEventType::Requested);
        try {
            $this->inspect($attachment, FakeMalwareScanner::clean());
            $this->fail('Concurrent scan accepted.');
        } catch (AttachmentScanException $exception) {
            $this->assertSame(AttachmentScanFailure::Interrupted, $exception->failure);
        }
        try {
            (new DeleteAttachment)->handle($request->submitter, $attachment, 'delete during scan');
            $this->fail('Scanning attachment deletion accepted.');
        } catch (ValidationException) {
            Storage::disk('attachments')->assertExists($attachment->storage_path);
        }
        $this->travel(6)->minutes();
        $clean = $this->inspect($attachment, FakeMalwareScanner::clean())->fresh();
        $events = $clean->scanEvents()->orderBy('id')->get();
        $this->assertSame([AttachmentScanEventType::Requested, AttachmentScanEventType::Failed,
            AttachmentScanEventType::Requested, AttachmentScanEventType::Clean], $events->pluck('event_type')->all());
        $this->assertSame(AttachmentScanFailure::Interrupted, $events[1]->failure_code);
        $this->assertSame($attempt, $events[1]->attempt_id);
        $this->assertSame(AttachmentScanStatus::Clean, $clean->scan_status);
    }

    public function test_outcome_history_failure_leaves_recoverable_scanning_state(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        $failedOnce = false;
        AttachmentScanEvent::creating(function (AttachmentScanEvent $event) use (&$failedOnce): void {
            if (! $failedOnce && $event->event_type === AttachmentScanEventType::Clean) {
                $failedOnce = true;
                throw new RuntimeException('Synthetic scan history failure.');
            }
        });
        try {
            $this->inspect($attachment, FakeMalwareScanner::clean());
            $this->fail('Outcome history failure not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic scan history failure.', $exception->getMessage());
        }
        $this->assertSame(AttachmentScanStatus::Scanning, $attachment->fresh()->scan_status);
        $this->assertSame(1, $attachment->scanEvents()->count());
        $this->travel(6)->minutes();
        $clean = $this->inspect($attachment, FakeMalwareScanner::clean())->fresh();
        $this->assertSame(AttachmentScanStatus::Clean, $clean->scan_status);
        $this->assertSame(4, $clean->scanEvents()->count());
    }

    public function test_changed_or_unavailable_storage_fails_closed_with_safe_codes(): void
    {
        $request = WorkRequest::factory()->create();
        $missing = $this->attachment($request);
        Storage::disk('attachments')->put($missing->storage_path, str_repeat('x', $missing->size_bytes));
        try {
            $this->inspect($missing, FakeMalwareScanner::clean());
            $this->fail('Missing content accepted.');
        } catch (AttachmentScanException $exception) {
            $this->assertSame(AttachmentScanFailure::ContentChanged, $exception->failure);
        }
        $this->assertSame(AttachmentScanStatus::Failed, $missing->fresh()->scan_status);
        $misconfigured = $this->attachment($request);
        config(['filesystems.disks.attachments.visibility' => 'public']);
        try {
            $this->inspect($misconfigured, FakeMalwareScanner::clean());
            $this->fail('Public storage accepted.');
        } catch (AttachmentScanException $exception) {
            $this->assertSame(AttachmentScanFailure::StorageUnavailable, $exception->failure);
        }
        $this->assertSame(AttachmentScanFailure::StorageUnavailable,
            $misconfigured->scanEvents()->latest('id')->first()->failure_code);
    }

    public function test_estimate_submission_requires_clean_active_attachments(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        PricingRule::factory()->create();
        $assessment = (new AssessRequestPricing)->handle(
            $operator,
            $request,
            WorkDifficulty::Normal,
            30,
            today(),
            'scan submission test',
        );
        $draft = (new CreateEstimateVersion)->handle(
            $operator,
            $request,
            $assessment,
            'included',
            'excluded',
            today(),
            today()->startOfMonth(),
        );
        $pending = $this->attachment($request, $operator, $draft);
        try {
            (new SubmitEstimateVersion)->handle($operator, $draft);
            $this->fail('Estimate with pending attachment submitted.');
        } catch (ValidationException) {
            $this->assertNull($draft->fresh()->submitted_at);
        }
        $this->inspect($pending, FakeMalwareScanner::clean());
        $infected = $this->inspect(
            $this->attachment($request, $operator, $draft),
            FakeMalwareScanner::infected(),
        );
        try {
            (new SubmitEstimateVersion)->handle($operator, $draft);
            $this->fail('Estimate with infected attachment submitted.');
        } catch (ValidationException) {
            $this->assertNull($draft->fresh()->submitted_at);
        }
        (new DeleteAttachment)->handle($operator, $infected, 'remove quarantined estimate file');
        $this->assertNotNull((new SubmitEstimateVersion)->handle($operator, $draft)->submitted_at);
    }

    public function test_scan_history_is_immutable_and_direct_state_jumps_are_blocked(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->inspect($this->attachment($request), FakeMalwareScanner::infected());
        $event = $attachment->scanEvents()->firstOrFail();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $event->forceFill(['scanner' => 'changed'])->save() : $event->delete();
                $this->fail('Scan audit mutation accepted.');
            } catch (LogicException) {
                $this->assertSame(2, AttachmentScanEvent::count());
            }
        }
        try {
            DB::table('attachment_scan_events')->where('id', $event->id)->delete();
            $this->fail('SQL scan history deletion accepted.');
        } catch (QueryException) {
            $this->assertSame(2, AttachmentScanEvent::count());
        }
        try {
            DB::table('attachments')->where('id', $attachment->id)->update([
                'scan_status' => 'scanning', 'scan_attempt_id' => (string) Str::uuid(),
                'scan_requested_at' => now(), 'scanned_at' => null,
            ]);
            $this->fail('Infected attachment released for rescanning.');
        } catch (QueryException) {
            $this->assertSame(AttachmentScanStatus::Infected, $attachment->fresh()->scan_status);
        }
        $pending = $this->attachment($request);
        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $pending->id)->update(['scan_status' => 'clean', 'scanned_at' => now()]);
    }

    private function attachment(
        WorkRequest $request,
        ?User $actor = null,
        WorkRequest|EstimateVersion|null $target = null,
    ): Attachment {
        $stream = tmpfile();
        fwrite($stream, "attachment scanning fixture\n");
        $this->files[] = $stream;
        $file = new UploadedFile(stream_get_meta_data($stream)['uri'], 'scan.txt', 'text/plain', UPLOAD_ERR_OK, true);

        return (new RegisterAttachment)->handle($actor ?? $request->submitter, $target ?? $request, $file);
    }

    private function inspect(Attachment $attachment, FakeMalwareScanner $scanner, bool $force = false): Attachment
    {
        return (new InspectAttachment(new ScanAttachmentContent($scanner)))->handle($attachment, $force);
    }

    private function event(Attachment $attachment, string $attemptId, AttachmentScanEventType $type): void
    {
        $event = new AttachmentScanEvent;
        $event->forceFill(['company_id' => $attachment->company_id, 'attachment_id' => $attachment->id,
            'attempt_id' => $attemptId, 'event_type' => $type, 'scanner' => 'clamav',
            'occurred_at' => now()])->save();
    }
}
