<?php

namespace Tests\Feature\Models;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateAttachmentLink;
use App\Actions\CreateEstimateVersion;
use App\Actions\RegisterAttachment;
use App\Actions\SubmitEstimateVersion;
use App\Enums\AttachmentScanStatus;
use App\Enums\WorkDifficulty;
use App\Models\Attachment;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Concerns\ScansAttachments;
use Tests\TestCase;

class AttachmentMetadataTest extends TestCase
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

    private function file(string $name = '업무 자료.txt', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $stream = tmpfile();
        fwrite($stream, "Synthetic attachment content.\n");
        $this->files[] = $stream;

        return new UploadedFile(stream_get_meta_data($stream)['uri'], $name, 'image/png', $error, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            fclose($file);
        }
        parent::tearDown();
    }

    public function test_registration_saves_server_metadata_actual_actor_and_pending_state(): void
    {
        $this->freezeTime();
        $request = WorkRequest::factory()->create();
        $file = $this->file();
        $attachment = (new RegisterAttachment)->handle($request->submitter, $request, $file)->fresh();
        $this->assertSame('업무 자료.txt', $attachment->original_name);
        $this->assertSame(strlen("Synthetic attachment content.\n"), $attachment->size_bytes);
        $this->assertSame('text/plain', $attachment->mime_type);
        $this->assertSame($request->submitted_by, $attachment->uploaded_by);
        $this->assertTrue($attachment->uploader->is($request->submitter));
        $this->assertSame($request->company_id, $attachment->company_id);
        $this->assertSame(AttachmentScanStatus::Pending, $attachment->scan_status);
        $this->assertNull($attachment->scanned_at);
        $this->assertTrue($attachment->uploaded_at->equalTo(now()->startOfSecond()));
        $this->assertFileExists($file->getPathname());
    }

    /** @return array<string, array{string, int}> */
    public static function badFiles(): array
    {
        return ['blank' => [' ', UPLOAD_ERR_OK], 'long' => [str_repeat('a', 256), UPLOAD_ERR_OK],
            'control' => ["bad\nname.txt", UPLOAD_ERR_OK], 'dot' => ['..', UPLOAD_ERR_OK],
            'partial' => ['file.txt', UPLOAD_ERR_PARTIAL], 'missing' => ['file.txt', UPLOAD_ERR_NO_FILE]];
    }

    #[DataProvider('badFiles')]
    public function test_invalid_file_metadata_rolls_back_the_link(string $name, int $error): void
    {
        $request = WorkRequest::factory()->create();
        try {
            (new RegisterAttachment)->handle($request->submitter, $request, $this->file($name, $error));
            $this->fail('Invalid file accepted');
        } catch (ValidationException) {
            $this->assertSame(0, Attachment::count());
        }
    }

    public function test_unauthorized_and_stale_inactive_actor_cannot_register_metadata(): void
    {
        $request = WorkRequest::factory()->create();
        $operator = User::factory()->operator()->create();
        User::whereKey($operator->id)->update(['is_active' => false]);
        foreach ([User::factory()->customerAdmin()->create(), $operator] as $actor) {
            try {
                (new RegisterAttachment)->handle($actor, $request, $this->file());
                $this->fail('Unauthorized file registered');
            } catch (AuthorizationException) {
                $this->assertSame(0, Attachment::count());
            }
        }
    }

    /** @return array<string, array{string, mixed}> */
    public static function immutableFields(): array
    {
        return ['name' => ['original_name', 'changed.txt'], 'name case' => ['original_name', '업무 자료.TXT'],
            'mime case' => ['mime_type', 'TEXT/PLAIN'], 'size' => ['size_bytes', 1],
            'mime' => ['mime_type', 'image/png'], 'disk' => ['storage_disk', 'local'],
            'path' => ['storage_path', 'objects/changed'], 'hash' => ['content_sha256', str_repeat('0', 64)],
            'actor' => ['uploaded_by', null],
            'time' => ['uploaded_at', '2000-01-01 00:00:00']];
    }

    #[DataProvider('immutableFields')]
    public function test_registered_metadata_is_immutable_in_models_and_direct_sql(string $field, mixed $value): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = (new RegisterAttachment)->handle($request->submitter, $request, $this->file());
        try {
            $attachment->forceFill([$field => $value])->save();
            $this->fail('Model metadata changed');
        } catch (LogicException) {
            $this->assertNotSame($value, $attachment->fresh()->getAttribute($field));
        }
        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $attachment->id)->update([$field => $value]);
    }

    public function test_scan_states_are_persisted_with_timestamps_without_marking_new_files_clean(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = null;
        foreach ([AttachmentScanStatus::Clean, AttachmentScanStatus::Infected, AttachmentScanStatus::Failed] as $state) {
            $attachment = $this->scanAttachmentAs(
                (new RegisterAttachment)->handle($request->submitter, $request, $this->file()),
                $state,
            );
            $this->assertSame($state, $attachment->scan_status);
            $this->assertNotNull($attachment->scanned_at);
        }
        foreach ([['scan_status' => 'unknown'], ['scan_status' => 'clean', 'scanned_at' => null],
            ['scan_status' => 'pending', 'scanned_at' => now()]] as $values) {
            try {
                DB::table('attachments')->where('id', $attachment->id)->update($values);
                $this->fail('Invalid scan state accepted');
            } catch (QueryException) {
                $this->assertSame(AttachmentScanStatus::Failed, $attachment->fresh()->scan_status);
            }
        }
        $row = $attachment->fresh()->getAttributes();
        unset($row['id']);
        $row['scan_status'] = 'clean';
        $this->expectException(QueryException::class);
        DB::table('attachments')->insert($row);
    }

    public function test_legacy_links_reject_partial_metadata_and_cannot_be_scanned(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        foreach ([['original_name' => 'partial.txt'], ['scan_status' => 'clean', 'scanned_at' => now()]] as $values) {
            try {
                DB::table('attachments')->where('id', $link->id)->update($values);
                $this->fail('Incomplete metadata accepted');
            } catch (QueryException) {
                $this->assertNull($link->fresh()->original_name);
                $this->assertSame(AttachmentScanStatus::Pending, $link->fresh()->scan_status);
            }
        }
    }

    public function test_comment_and_estimate_metadata_respect_submission_freeze(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $comment = WorkRequestComment::factory()->create(['company_id' => $request->company_id,
            'work_request_id' => $request->id, 'author_id' => $request->submitted_by]);
        $commentFile = (new RegisterAttachment)->handle($request->submitter, $comment, $this->file());
        $this->assertSame($comment->id, $commentFile->work_request_comment_id);
        PricingRule::factory()->create();
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, 30, today(), '첨부 검증');
        $draft = (new CreateEstimateVersion)->handle($operator, $request, $assessment, '포함', '제외', today(), today()->startOfMonth());
        $legacy = (new CreateAttachmentLink)->handle($operator, $draft);
        $registered = (new RegisterAttachment)->handle($operator, $draft, $this->file());
        $this->assertSame($draft->id, $registered->estimate_version_id);
        $this->assertSame($operator->id, $registered->uploaded_by);
        $registered = $this->scanAttachmentAs($registered, AttachmentScanStatus::Clean);
        (new SubmitEstimateVersion)->handle($operator, $draft);
        try {
            (new RegisterAttachment)->handle($operator, $draft, $this->file());
            $this->fail('Submitted estimate file registered');
        } catch (AuthorizationException) {
            $this->assertSame(3, Attachment::count());
        }
        $registered = $this->scanAttachmentAs($registered, AttachmentScanStatus::Clean, true);
        $this->assertSame(AttachmentScanStatus::Clean, $registered->scan_status);
        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $legacy->id)->update(['original_name' => 'late.txt',
            'size_bytes' => 1, 'mime_type' => 'text/plain', 'uploaded_by' => $operator->id, 'uploaded_at' => now()]);
    }

    public function test_uploader_reference_cannot_be_deleted(): void
    {
        $operator = User::factory()->operator()->create();
        (new RegisterAttachment)->handle($operator, WorkRequest::factory()->create(), $this->file());
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $operator->id)->delete();
    }
}
