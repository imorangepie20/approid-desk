<?php

namespace Tests\Feature;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateAttachmentLink;
use App\Actions\CreateEstimateVersion;
use App\Actions\RegisterAttachment;
use App\Actions\SubmitEstimateVersion;
use App\Enums\AttachmentScanStatus;
use App\Enums\WorkDifficulty;
use App\Models\Attachment;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Concerns\ScansAttachments;
use Tests\TestCase;

class AttachmentDownloadTest extends TestCase
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

    private function file(string $name = '고객 자료.txt', string $contents = "private download bytes\n"): UploadedFile
    {
        $stream = tmpfile();
        fwrite($stream, $contents);
        $this->files[] = $stream;

        return new UploadedFile(stream_get_meta_data($stream)['uri'], $name, 'text/plain', UPLOAD_ERR_OK, true);
    }

    private function cleanAttachment(WorkRequest $request, ?User $actor = null, WorkRequest|EstimateVersion|null $target = null): Attachment
    {
        $attachment = (new RegisterAttachment)->handle($actor ?? $request->submitter, $target ?? $request, $this->file());

        return $this->scanAttachmentAs($attachment, AttachmentScanStatus::Clean);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            fclose($file);
        }
        parent::tearDown();
    }

    public function test_authorized_download_streams_exact_private_bytes_with_safe_headers_and_original_name(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->cleanAttachment($request);
        $url = route('attachments.download', $attachment);
        $this->assertStringNotContainsString($attachment->storage_path, $url);
        foreach ([$request->submitter, User::factory()->customerAdmin()->for($request->company)->create(),
            User::factory()->operator()->create(), User::factory()->superAdmin()->create()] as $actor) {
            $response = $this->actingAs($actor)->get($url);
            $response->assertOk()->assertDownload('attachment-'.$attachment->id.'.txt')
                ->assertHeader('Content-Type', 'application/octet-stream')
                ->assertHeader('Content-Length', (string) $attachment->size_bytes)
                ->assertHeader('Pragma', 'no-cache')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            foreach (['private', 'no-store', 'max-age=0'] as $directive) {
                $this->assertStringContainsString($directive, (string) $response->headers->get('Cache-Control'));
            }
            $this->assertStringContainsString("filename*=utf-8''".rawurlencode('고객 자료.txt'),
                (string) $response->headers->get('Content-Disposition'));
            $this->assertSame("private download bytes\n", $response->streamedContent());
        }
    }

    public function test_download_requires_authentication_active_access_and_company_scope(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->cleanAttachment($request);
        $url = route('attachments.download', $attachment);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->customerUser()->create())->get($url)->assertForbidden();
        $unverified = User::factory()->unverified()->customerUser()->for($request->company)->create();
        $this->actingAs($unverified)->get($url)->assertRedirect(route('verification.notice'));
        $inactive = User::factory()->operator()->inactive()->create();
        $this->actingAs($inactive)->get($url)->assertForbidden();
        $sameCompany = User::factory()->customerUser()->for($request->company)->create();
        $request->company->update(['status' => 'inactive']);
        $this->actingAs($sameCompany)->get($url)->assertForbidden();
        $this->get(route('attachments.download', 999999))->assertNotFound();
    }

    public function test_customer_cannot_download_draft_estimate_attachment_until_submission(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        PricingRule::factory()->create();
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, 30, today(), 'download test');
        $draft = (new CreateEstimateVersion)->handle($operator, $request, $assessment, 'included', 'excluded', today(), today()->startOfMonth());
        $attachment = $this->cleanAttachment($request, $operator, $draft);
        $url = route('attachments.download', $attachment);
        $this->actingAs($request->submitter)->get($url)->assertForbidden();
        $this->actingAs($operator)->get($url)->assertOk();
        (new SubmitEstimateVersion)->handle($operator, $draft);
        $response = $this->actingAs($request->submitter)->get($url);
        $response->assertOk();
        $this->assertSame("private download bytes\n", $response->streamedContent());
    }

    /** @return array<string, array{AttachmentScanStatus}> */
    public static function unsafeStates(): array
    {
        return ['pending' => [AttachmentScanStatus::Pending], 'scanning' => [AttachmentScanStatus::Scanning],
            'infected' => [AttachmentScanStatus::Infected], 'failed' => [AttachmentScanStatus::Failed]];
    }

    #[DataProvider('unsafeStates')]
    public function test_only_clean_files_can_be_downloaded(AttachmentScanStatus $state): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = (new RegisterAttachment)->handle($request->submitter, $request, $this->file());
        if ($state === AttachmentScanStatus::Scanning) {
            $attachment->forceFill(['scan_status' => $state, 'scan_attempt_id' => (string) Str::uuid(),
                'scan_requested_at' => now()])->save();
        } elseif ($state !== AttachmentScanStatus::Pending) {
            $attachment = $this->scanAttachmentAs($attachment, $state);
        }
        $this->actingAs($request->submitter)->get(route('attachments.download', $attachment))
            ->assertConflict()->assertSee('안전 검사가 완료된 첨부파일만');
        Storage::disk('attachments')->assertExists($attachment->storage_path);
    }

    public function test_legacy_link_without_a_file_is_not_downloadable(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        $this->actingAs($request->submitter)->get(route('attachments.download', $link))->assertConflict();
    }

    public function test_missing_file_or_non_private_disk_fails_without_exposing_storage_details(): void
    {
        $request = WorkRequest::factory()->create();
        $missing = $this->cleanAttachment($request);
        Storage::disk('attachments')->delete($missing->storage_path);
        $response = $this->actingAs($request->submitter)->get(route('attachments.download', $missing));
        $response->assertServiceUnavailable()->assertDontSee($missing->storage_path);

        $misconfigured = $this->cleanAttachment($request);
        config(['filesystems.disks.attachments.visibility' => 'public']);
        $response = $this->get(route('attachments.download', $misconfigured));
        $response->assertServiceUnavailable()->assertDontSee($misconfigured->storage_path);
    }

    public function test_file_size_change_after_scan_is_rejected(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->cleanAttachment($request);
        Storage::disk('attachments')->put($attachment->storage_path, 'changed after clean scan');
        $response = $this->actingAs($request->submitter)->get(route('attachments.download', $attachment));
        $response->assertServiceUnavailable()->assertDontSee($attachment->storage_path);
    }

    public function test_same_size_content_change_after_scan_is_rejected(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->cleanAttachment($request);
        Storage::disk('attachments')->put($attachment->storage_path, str_repeat('x', $attachment->size_bytes));
        $response = $this->actingAs($request->submitter)->get(route('attachments.download', $attachment));
        $response->assertServiceUnavailable()->assertDontSee($attachment->storage_path);
    }

    public function test_malformed_stored_path_is_rejected_before_storage_access(): void
    {
        $request = WorkRequest::factory()->create();
        $id = DB::table('attachments')->insertGetId(['company_id' => $request->company_id,
            'work_request_id' => $request->id, 'original_name' => 'unsafe.txt', 'size_bytes' => 1,
            'mime_type' => 'text/plain', 'storage_disk' => 'attachments', 'storage_path' => '../unsafe',
            'uploaded_by' => $request->submitted_by, 'uploaded_at' => now(), 'scan_status' => 'pending',
            'created_at' => now(), 'updated_at' => now()]);
        $attempt = (string) Str::uuid();
        DB::table('attachments')->where('id', $id)->update(['scan_status' => 'scanning',
            'scan_attempt_id' => $attempt, 'scan_requested_at' => now()]);
        DB::table('attachments')->where('id', $id)->update(['scan_status' => 'clean', 'scanned_at' => now()]);
        $response = $this->actingAs($request->submitter)->get(route('attachments.download', $id));
        $response->assertServiceUnavailable()->assertDontSee('../unsafe');
    }

    public function test_download_route_is_rate_limited_per_user_without_blocking_another_user(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = $this->cleanAttachment($request);
        $url = route('attachments.download', $attachment);
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($request->submitter)->get($url)->assertOk();
        }
        $this->get($url)->assertTooManyRequests()->assertHeader('Retry-After');
        $other = User::factory()->customerAdmin()->for($request->company)->create();
        $this->actingAs($other)->get($url)->assertOk();
        $route = Route::getRoutes()->getByName('attachments.download');
        $this->assertNotNull($route);
        $this->assertContains('throttle:attachment-download', $route->gatherMiddleware());

        $limiter = RateLimiter::limiter('attachment-download');
        $this->assertIsCallable($limiter);
        $rateRequest = Request::create($url, server: ['REMOTE_ADDR' => '203.0.113.8']);
        $rateRequest->setUserResolver(fn () => $request->submitter);
        $limits = $limiter($rateRequest);
        $this->assertSame([10, 100], array_column($limits, 'maxAttempts'));
        $this->assertSame([60, 3600], array_column($limits, 'decaySeconds'));
        $this->assertSame(['attachment-download:user:'.$request->submitted_by,
            'attachment-download:ip:203.0.113.8'], array_column($limits, 'key'));
    }
}
