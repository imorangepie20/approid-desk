<?php

namespace Tests\Feature\Models;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateAttachmentLink;
use App\Actions\CreateEstimateVersion;
use App\Actions\DeleteAttachment;
use App\Actions\RegisterAttachment;
use App\Actions\SubmitEstimateVersion;
use App\Enums\AttachmentDeletionEventType;
use App\Enums\AttachmentDeletionFailure;
use App\Enums\AttachmentDeletionStatus;
use App\Enums\AttachmentScanStatus;
use App\Enums\WorkDifficulty;
use App\Models\Attachment;
use App\Models\AttachmentDeletionEvent;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Services\DeleteAttachmentContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Concerns\ScansAttachments;
use Tests\TestCase;

class AttachmentDeletionTest extends TestCase
{
    use RefreshDatabase, ScansAttachments;

    /** @var array<int, resource> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('attachments');
    }

    private function file(string $contents = "attachment deletion fixture\n"): UploadedFile
    {
        $stream = tmpfile();
        fwrite($stream, $contents);
        $this->files[] = $stream;

        return new UploadedFile(stream_get_meta_data($stream)['uri'], '삭제 대상.txt', 'text/plain', UPLOAD_ERR_OK, true);
    }

    private function attachment(WorkRequest $request, ?User $actor = null, WorkRequest|EstimateVersion|null $target = null): Attachment
    {
        return (new RegisterAttachment)->handle($actor ?? $request->submitter, $target ?? $request, $this->file());
    }

    private function draft(User $operator, WorkRequest $request): EstimateVersion
    {
        PricingRule::factory()->create();
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, 30, today(), 'delete test');

        return (new CreateEstimateVersion)->handle($operator, $request, $assessment, 'included', 'excluded', today(), today()->startOfMonth());
    }

    private function failingContent(AttachmentDeletionFailure $failure): DeleteAttachmentContent
    {
        return new class($failure) extends DeleteAttachmentContent
        {
            public function __construct(private AttachmentDeletionFailure $failure) {}

            public function handle(Attachment $attachment): ?AttachmentDeletionFailure
            {
                return $this->failure;
            }
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            fclose($file);
        }
        parent::tearDown();
    }

    public function test_successful_deletion_preserves_tombstone_records_history_and_frees_request_slot(): void
    {
        config(['attachments.max_per_request' => 1]);
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        $path = $attachment->storage_path;
        $deleted = (new DeleteAttachment)->handle($request->submitter, $attachment, '  잘못 올린 파일  ')->fresh();
        $this->assertSame(AttachmentDeletionStatus::Deleted, $deleted->deletion_status);
        $this->assertSame($request->submitted_by, $deleted->deletion_requested_by);
        $this->assertNotNull($deleted->deletion_requested_at);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame($path, $deleted->storage_path);
        Storage::disk('attachments')->assertMissing($path);
        $events = $deleted->deletionEvents()->orderBy('id')->get();
        $this->assertSame([AttachmentDeletionEventType::Requested, AttachmentDeletionEventType::Succeeded],
            $events->pluck('event_type')->all());
        $this->assertSame('잘못 올린 파일', $events[0]->reason);
        $this->assertSame($events[0]->attempt_id, $events[1]->attempt_id);
        $this->assertSame([$request->submitted_by, $request->submitted_by], $events->pluck('actor_id')->all());
        $this->assertSame(2, AttachmentDeletionEvent::visibleTo($request->submitter)->count());
        $this->assertSame(0, AttachmentDeletionEvent::visibleTo(User::factory()->customerUser()->create())->count());
        $this->assertSame(2, AttachmentDeletionEvent::visibleTo(User::factory()->operator()->create())->count());
        $this->assertSame(0, AttachmentDeletionEvent::visibleTo(User::factory()->operator()->inactive()->create())->count());
        $this->actingAs($request->submitter)->get(route('attachments.download', $deleted))->assertGone();
        $replacement = $this->attachment($request);
        $this->assertSame(2, Attachment::count());
        $this->assertSame(AttachmentDeletionStatus::Active, $replacement->deletion_status);
        $this->assertTrue((new DeleteAttachment)->handle($request->submitter, $deleted, 'idempotent')->is($deleted));
        $this->assertSame(2, $deleted->deletionEvents()->count());
    }

    public function test_failed_storage_deletion_is_audited_and_can_be_retried(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        try {
            (new DeleteAttachment($this->failingContent(AttachmentDeletionFailure::StorageDeleteFailed)))
                ->handle($request->submitter, $attachment, 'first attempt');
            $this->fail('Storage deletion failure accepted');
        } catch (ValidationException $exception) {
            $this->assertSame(['attachment'], array_keys($exception->errors()));
        }
        $failed = $attachment->fresh();
        $this->assertSame(AttachmentDeletionStatus::Failed, $failed->deletion_status);
        Storage::disk('attachments')->assertExists($failed->storage_path);
        $this->assertSame([AttachmentDeletionEventType::Requested, AttachmentDeletionEventType::Failed],
            $failed->deletionEvents()->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(AttachmentDeletionFailure::StorageDeleteFailed,
            $failed->deletionEvents()->latest('id')->first()->failure_code);
        $firstAttempt = $failed->deletion_attempt_id;
        $deleted = (new DeleteAttachment)->handle($request->submitter, $failed, 'retry')->fresh();
        $this->assertSame(AttachmentDeletionStatus::Deleted, $deleted->deletion_status);
        $this->assertNotSame($firstAttempt, $deleted->deletion_attempt_id);
        $this->assertSame(4, $deleted->deletionEvents()->count());
        $this->assertSame(2, $deleted->deletionEvents()->distinct()->count('attempt_id'));
        Storage::disk('attachments')->assertMissing($deleted->storage_path);
    }

    public function test_unsafe_storage_configuration_records_a_generic_failure_without_removing_content(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        config(['filesystems.disks.attachments.visibility' => 'public']);
        try {
            (new DeleteAttachment)->handle($request->submitter, $attachment, 'unsafe storage');
            $this->fail('Unsafe storage deletion accepted');
        } catch (ValidationException) {
            $failed = $attachment->fresh();
            $this->assertSame(AttachmentDeletionStatus::Failed, $failed->deletion_status);
            $this->assertSame(AttachmentDeletionFailure::StorageUnavailable,
                $failed->deletionEvents()->latest('id')->first()->failure_code);
            Storage::disk('attachments')->assertExists($failed->storage_path);
        }
    }

    public function test_requested_history_failure_rolls_back_before_storage_is_touched(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        AttachmentDeletionEvent::creating(function (AttachmentDeletionEvent $event): void {
            if ($event->event_type === AttachmentDeletionEventType::Requested) {
                throw new RuntimeException('Synthetic requested history failure');
            }
        });
        try {
            (new DeleteAttachment)->handle($request->submitter, $attachment, 'delete');
            $this->fail('Requested history failure not raised');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic requested history failure', $exception->getMessage());
            $this->assertSame(AttachmentDeletionStatus::Active, $attachment->fresh()->deletion_status);
            $this->assertSame(0, AttachmentDeletionEvent::count());
            Storage::disk('attachments')->assertExists($attachment->storage_path);
        }
    }

    public function test_recent_pending_attempt_is_rejected_and_stale_attempt_is_recovered_with_history(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        $attempt = (string) Str::uuid();
        $attachment->forceFill(['deletion_status' => AttachmentDeletionStatus::Pending,
            'deletion_attempt_id' => $attempt, 'deletion_requested_by' => $request->submitted_by,
            'deletion_requested_at' => now()])->save();
        $this->event($attachment, $request->submitter, $attempt, AttachmentDeletionEventType::Requested, 'original');
        try {
            (new DeleteAttachment)->handle($request->submitter, $attachment, 'too soon');
            $this->fail('Concurrent retry accepted');
        } catch (ValidationException) {
            $this->assertSame(1, $attachment->deletionEvents()->count());
            Storage::disk('attachments')->assertExists($attachment->storage_path);
        }
        $this->travel(6)->minutes();
        $deleted = (new DeleteAttachment)->handle($request->submitter, $attachment, 'recover')->fresh();
        $events = $deleted->deletionEvents()->orderBy('id')->get();
        $this->assertSame([AttachmentDeletionEventType::Requested, AttachmentDeletionEventType::Failed,
            AttachmentDeletionEventType::Requested, AttachmentDeletionEventType::Succeeded], $events->pluck('event_type')->all());
        $this->assertSame(AttachmentDeletionFailure::Interrupted, $events[1]->failure_code);
        $this->assertSame($attempt, $events[1]->attempt_id);
        $this->assertSame(AttachmentDeletionStatus::Deleted, $deleted->deletion_status);
    }

    public function test_outcome_history_failure_leaves_recoverable_pending_state_after_file_removal(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        $failedOnce = false;
        AttachmentDeletionEvent::creating(function (AttachmentDeletionEvent $event) use (&$failedOnce): void {
            if (! $failedOnce && $event->event_type === AttachmentDeletionEventType::Succeeded) {
                $failedOnce = true;
                throw new RuntimeException('Synthetic outcome history failure');
            }
        });
        try {
            (new DeleteAttachment)->handle($request->submitter, $attachment, 'delete');
            $this->fail('History failure not raised');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic outcome history failure', $exception->getMessage());
        }
        $pending = $attachment->fresh();
        $this->assertSame(AttachmentDeletionStatus::Pending, $pending->deletion_status);
        $this->assertSame(1, $pending->deletionEvents()->count());
        Storage::disk('attachments')->assertMissing($pending->storage_path);
        $this->travel(6)->minutes();
        $deleted = (new DeleteAttachment)->handle($request->submitter, $pending, 'recover')->fresh();
        $this->assertSame(AttachmentDeletionStatus::Deleted, $deleted->deletion_status);
        $this->assertSame(4, $deleted->deletionEvents()->count());
    }

    public function test_permissions_and_submitted_estimates_block_deletion_and_direct_state_changes(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        foreach ([User::factory()->customerAdmin()->for($request->company)->create(),
            User::factory()->customerUser()->create(), User::factory()->operator()->inactive()->create()] as $actor) {
            try {
                (new DeleteAttachment)->handle($actor, $attachment, 'unauthorized');
                $this->fail('Unauthorized deletion accepted');
            } catch (AuthorizationException) {
                $this->assertSame(AttachmentDeletionStatus::Active, $attachment->fresh()->deletion_status);
                Storage::disk('attachments')->assertExists($attachment->storage_path);
            }
        }
        $draft = $this->draft($operator, $request);
        $estimateAttachment = $this->attachment($request, $operator, $draft);
        $estimateAttachment = $this->scanAttachmentAs($estimateAttachment, AttachmentScanStatus::Clean);
        (new SubmitEstimateVersion)->handle($operator, $draft);
        try {
            (new DeleteAttachment)->handle($operator, $estimateAttachment, 'submitted');
            $this->fail('Submitted estimate attachment deleted');
        } catch (AuthorizationException) {
            $this->assertSame(AttachmentDeletionStatus::Active, $estimateAttachment->fresh()->deletion_status);
        }
        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $estimateAttachment->id)->update([
            'deletion_status' => 'pending', 'deletion_attempt_id' => (string) Str::uuid(),
            'deletion_requested_by' => $operator->id, 'deletion_requested_at' => now(),
        ]);
    }

    public function test_failed_estimate_deletion_blocks_submission_until_retry_succeeds(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $draft = $this->draft($operator, $request);
        $attachment = $this->attachment($request, $operator, $draft);
        try {
            (new DeleteAttachment($this->failingContent(AttachmentDeletionFailure::StorageUnavailable)))
                ->handle($operator, $attachment, 'remove draft file');
        } catch (ValidationException) {
            $this->assertSame(AttachmentDeletionStatus::Failed, $attachment->fresh()->deletion_status);
        }
        $this->assertSame(0, AttachmentDeletionEvent::visibleTo($request->submitter)->count());
        $this->assertSame(2, AttachmentDeletionEvent::visibleTo($operator)->count());
        try {
            (new SubmitEstimateVersion)->handle($operator, $draft);
            $this->fail('Estimate with failed deletion submitted');
        } catch (ValidationException $exception) {
            $this->assertSame(['attachment'], array_keys($exception->errors()));
            $this->assertNull($draft->fresh()->submitted_at);
        }
        (new DeleteAttachment)->handle($operator, $attachment->fresh(), 'retry');
        $this->assertNotNull((new SubmitEstimateVersion)->handle($operator, $draft)->submitted_at);
    }

    public function test_legacy_empty_link_can_be_deleted_without_storage_content(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($operator, $request);
        $deleted = (new DeleteAttachment)->handle($operator, $link, 'remove legacy link')->fresh();
        $this->assertSame(AttachmentDeletionStatus::Deleted, $deleted->deletion_status);
        $this->assertNull($deleted->storage_path);
        $this->assertSame(2, $deleted->deletionEvents()->count());
    }

    public function test_deletion_records_are_immutable_and_attachment_rows_cannot_be_hard_deleted(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = (new DeleteAttachment)->handle($request->submitter, $this->attachment($request), 'audit');
        $event = $attachment->deletionEvents()->firstOrFail();
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $event->forceFill(['reason' => 'changed'])->save() : $event->delete();
                $this->fail('Audit mutation accepted');
            } catch (LogicException) {
                $this->assertSame(2, AttachmentDeletionEvent::count());
            }
        }
        try {
            $attachment->delete();
            $this->fail('Attachment hard deletion accepted');
        } catch (LogicException) {
            $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        }
        try {
            DB::table('attachment_deletion_events')->where('id', $event->id)->delete();
            $this->fail('SQL audit deletion accepted');
        } catch (QueryException) {
            $this->assertSame(2, AttachmentDeletionEvent::count());
        }
        try {
            DB::table('attachment_deletion_events')->where('id', $event->id)->update(['reason' => 'changed']);
            $this->fail('SQL audit update accepted');
        } catch (QueryException) {
            $this->assertSame('audit', $event->fresh()->reason);
        }
        try {
            DB::table('attachments')->where('id', $attachment->id)->update([
                'scan_status' => 'failed', 'scanned_at' => now(),
            ]);
            $this->fail('Deleted attachment update accepted');
        } catch (QueryException) {
            $this->assertSame(AttachmentDeletionStatus::Deleted, $attachment->fresh()->deletion_status);
        }
        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $attachment->id)->delete();
    }

    public function test_invalid_reason_and_direct_state_jump_leave_file_and_history_unchanged(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->attachment($request);
        foreach ([' ', str_repeat('a', 1001)] as $reason) {
            try {
                (new DeleteAttachment)->handle($request->submitter, $attachment, $reason);
                $this->fail('Invalid reason accepted');
            } catch (ValidationException) {
                $this->assertSame(AttachmentDeletionStatus::Active, $attachment->fresh()->deletion_status);
                $this->assertSame(0, AttachmentDeletionEvent::count());
                Storage::disk('attachments')->assertExists($attachment->storage_path);
            }
        }
        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $attachment->id)->update([
            'deletion_status' => 'deleted', 'deletion_attempt_id' => (string) Str::uuid(),
            'deletion_requested_by' => $request->submitted_by, 'deletion_requested_at' => now(), 'deleted_at' => now(),
        ]);
    }

    private function event(Attachment $attachment, User $actor, string $attempt,
        AttachmentDeletionEventType $type, ?string $reason = null): void
    {
        $event = new AttachmentDeletionEvent;
        $event->forceFill(['company_id' => $attachment->company_id, 'attachment_id' => $attachment->id,
            'actor_id' => $actor->id, 'attempt_id' => $attempt, 'event_type' => $type,
            'reason' => $reason, 'occurred_at' => now()])->save();
    }
}
