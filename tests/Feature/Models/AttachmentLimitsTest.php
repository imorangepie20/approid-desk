<?php

namespace Tests\Feature\Models;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateAttachmentLink;
use App\Actions\CreateEstimateVersion;
use App\Actions\RegisterAttachment;
use App\Enums\AttachmentScanStatus;
use App\Enums\WorkDifficulty;
use App\Models\Attachment;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Concerns\ScansAttachments;
use Tests\TestCase;

class AttachmentLimitsTest extends TestCase
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

    private function file(int $bytes): UploadedFile
    {
        $stream = tmpfile();
        fwrite($stream, str_repeat('a', $bytes));
        $this->files[] = $stream;

        return new UploadedFile(stream_get_meta_data($stream)['uri'], 'limit.txt', 'text/plain', UPLOAD_ERR_OK, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            fclose($file);
        }
        parent::tearDown();
    }

    public function test_actual_bytes_allow_exact_limit_and_reject_one_byte_over_without_consuming_slot(): void
    {
        config(['attachments.max_file_bytes' => 32, 'attachments.max_per_request' => 2]);
        $request = WorkRequest::factory()->create();
        $action = new RegisterAttachment;
        $this->assertSame(31, $action->handle($request->submitter, $request, $this->file(31))->size_bytes);
        try {
            $action->handle($request->submitter, $request, $this->file(33));
            $this->fail('Oversized file accepted');
        } catch (ValidationException $exception) {
            $this->assertSame(['file'], array_keys($exception->errors()));
            $this->assertSame(1, Attachment::count());
        }
        $this->assertSame(32, $action->handle($request->submitter, $request, $this->file(32))->size_bytes);
        $this->assertSame(2, Attachment::count());
    }

    public function test_request_comment_draft_and_legacy_links_share_count_without_visibility_filter(): void
    {
        config(['attachments.max_per_request' => 3]);
        $request = WorkRequest::factory()->create();
        $operator = User::factory()->operator()->create();
        $comment = WorkRequestComment::factory()->create(['company_id' => $request->company_id,
            'work_request_id' => $request->id, 'author_id' => $request->submitted_by]);
        PricingRule::factory()->create();
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, 30, today(), 'limit test');
        $draft = (new CreateEstimateVersion)->handle($operator, $request, $assessment, 'included', 'excluded', today(), today()->startOfMonth());
        (new CreateAttachmentLink)->handle($operator, $draft);
        $action = new RegisterAttachment;
        $action->handle($request->submitter, $comment, $this->file(10));
        $action->handle($request->submitter, $request, $this->file(10));
        foreach ([[$request->submitter, $request], [$request->submitter, $comment], [$operator, $draft]] as [$actor, $target]) {
            try {
                $action->handle($actor, $target, $this->file(10));
                $this->fail('Count limit bypassed');
            } catch (ValidationException $exception) {
                $this->assertSame(['file'], array_keys($exception->errors()));
                $this->assertSame(3, $request->attachments()->count());
            }
        }
        $other = WorkRequest::factory()->for($request->company)->create();
        $action->handle($operator, $other, $this->file(10));
        $this->assertSame(1, $other->attachments()->count());
        $foreign = WorkRequest::factory()->create();
        $action->handle($operator, $foreign, $this->file(10));
        $this->assertSame(1, $foreign->attachments()->count());
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidLimits(): array
    {
        $cases = [];
        foreach (['max_file_bytes', 'max_per_request'] as $key) {
            foreach ([null, 0, -1, true, 1.5, '20', 'bad', []] as $index => $value) {
                $cases[$key.' '.$index] = [$key, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_configuration_fails_closed(string $key, mixed $value): void
    {
        config(['attachments.'.$key => $value]);
        $request = WorkRequest::factory()->create();
        try {
            (new RegisterAttachment)->handle($request->submitter, $request, $this->file(10));
            $this->fail('Invalid configuration accepted');
        } catch (ValidationException $exception) {
            $this->assertSame(['file'], array_keys($exception->errors()));
            $this->assertSame(0, Attachment::count());
        }
    }

    public function test_tightening_limits_preserves_existing_files_and_all_scan_states_count(): void
    {
        $request = WorkRequest::factory()->create();
        foreach (AttachmentScanStatus::cases() as $state) {
            $file = (new RegisterAttachment)->handle($request->submitter, $request, $this->file(32));
            if ($state === AttachmentScanStatus::Scanning) {
                $file->forceFill(['scan_status' => $state, 'scan_attempt_id' => (string) Str::uuid(),
                    'scan_requested_at' => now()])->save();
            } elseif ($state !== AttachmentScanStatus::Pending) {
                $this->scanAttachmentAs($file, $state);
            }
        }
        foreach ([4, 1] as $limit) {
            config(['attachments.max_per_request' => $limit, 'attachments.max_file_bytes' => 1]);
            try {
                (new CreateAttachmentLink)->handle($request->submitter, $request);
                $this->fail('Legacy link bypassed limit');
            } catch (ValidationException) {
                $this->assertSame(5, Attachment::count());
                $this->assertSame(160, (int) Attachment::sum('size_bytes'));
            }
        }
    }

    public function test_authorization_precedes_limit_validation(): void
    {
        config(['attachments.max_per_request' => null]);
        $this->expectException(AuthorizationException::class);
        (new RegisterAttachment)->handle(User::factory()->customerAdmin()->create(), WorkRequest::factory()->create(), $this->file(10));
    }
}
