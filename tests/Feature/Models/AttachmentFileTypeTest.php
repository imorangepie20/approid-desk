<?php

namespace Tests\Feature\Models;

use App\Actions\RegisterAttachment;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\TestCase;

class AttachmentFileTypeTest extends TestCase
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

    private function file(string $name, string $kind, string $declared = 'application/octet-stream'): UploadedFile
    {
        $stream = tmpfile();
        $this->files[] = $stream;
        if (in_array($kind, ['png', 'jpeg'], true)) {
            $image = imagecreatetruecolor(2, 2);
            $kind === 'png' ? imagepng($image, $stream) : imagejpeg($image, $stream);
        } else {
            fwrite($stream, match ($kind) {
                'pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
                'csv' => "name,minutes\nalpha,30\nbeta,15\n",
                'html' => '<!DOCTYPE html><html><body>Not plain text</body></html>',
                'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>',
                'php' => '<?php echo "synthetic fixture"; ?>',
                'binary' => "\x00\x01\x02\x03\xFF\x00\x04",
                'empty' => '',
                default => "Synthetic plain text attachment.\n",
            });
        }
        fflush($stream);

        return new UploadedFile(stream_get_meta_data($stream)['uri'], $name, $declared, UPLOAD_ERR_OK, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            fclose($file);
        }
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function allowed(): array
    {
        return ['pdf' => ['자료.pdf', 'pdf'], 'png' => ['화면.png', 'png'],
            'jpg' => ['사진.jpg', 'jpeg'], 'jpeg uppercase' => ['사진.JPEG', 'jpeg'],
            'txt' => ['요청.txt', 'text'], 'csv' => ['시간.csv', 'csv'],
            'csv plain' => ['memo.csv', 'text'], 'multiple dots' => ['report.final.v2.PDF', 'pdf']];
    }

    #[DataProvider('allowed')]
    public function test_supported_extensions_match_real_bytes_despite_wrong_client_mime(string $name, string $kind): void
    {
        $request = WorkRequest::factory()->create();
        $file = $this->file($name, $kind, 'application/x-msdownload');
        $attachment = (new RegisterAttachment)->handle($request->submitter, $request, $file)->fresh();
        $this->assertSame($file->getMimeType(), $attachment->mime_type);
        $this->assertSame($name, $attachment->original_name);
        $this->assertSame(AttachmentScanStatus::Pending, $attachment->scan_status);
        $this->assertNull($attachment->scanned_at);
        $this->assertDatabaseCount('attachments', 1);
    }

    /** @return array<string, array{string, string}> */
    public static function rejected(): array
    {
        return ['text as pdf' => ['fake.pdf', 'text'], 'png as jpeg' => ['fake.jpg', 'png'],
            'jpeg as png' => ['fake.png', 'jpeg'], 'pdf as text' => ['fake.txt', 'pdf'],
            'html as text' => ['fake.txt', 'html'], 'html' => ['page.html', 'html'],
            'svg as png' => ['fake.png', 'svg'], 'svg' => ['drawing.svg', 'svg'],
            'php as jpg' => ['fake.jpg', 'php'], 'binary as csv' => ['fake.csv', 'binary'],
            'executable extension' => ['file.exe', 'text'], 'double extension' => ['file.pdf.exe', 'pdf'],
            'office' => ['file.docx', 'text'], 'archive' => ['file.zip', 'binary'],
            'missing extension' => ['file', 'text'], 'trailing dot' => ['file.txt.', 'text'],
            'trailing space' => ['file.txt ', 'text'], 'empty' => ['empty.txt', 'empty']];
    }

    #[DataProvider('rejected')]
    public function test_disallowed_or_mismatched_files_roll_back_registration(string $name, string $kind): void
    {
        $request = WorkRequest::factory()->create();
        try {
            (new RegisterAttachment)->handle($request->submitter, $request, $this->file($name, $kind, 'image/png'));
            $this->fail('Disallowed file registered');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('file', $error->errors());
            $this->assertSame(0, Attachment::count());
        }
    }

    public function test_missing_empty_or_malformed_allow_list_fails_closed(): void
    {
        $request = WorkRequest::factory()->create();
        foreach ([null, [], 'text/plain', ['txt' => 'text/plain'], ['txt' => []]] as $types) {
            config(['attachments.allowed_types' => $types]);
            try {
                (new RegisterAttachment)->handle($request->submitter, $request, $this->file('file.txt', 'text'));
                $this->fail('Missing allow list accepted');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('file', $error->errors());
                $this->assertSame(0, Attachment::count());
            }
        }
    }

    public function test_allow_list_changes_apply_to_new_registration_without_rewriting_old_records(): void
    {
        $request = WorkRequest::factory()->create();
        $existing = (new RegisterAttachment)->handle($request->submitter, $request, $this->file('file.txt', 'text'));
        config(['attachments.allowed_types' => ['pdf' => ['application/pdf']]]);
        try {
            (new RegisterAttachment)->handle($request->submitter, $request, $this->file('new.txt', 'text'));
            $this->fail('Removed type accepted');
        } catch (ValidationException) {
            $this->assertSame('text/plain', $existing->fresh()->mime_type);
            $this->assertSame(AttachmentScanStatus::Pending, $existing->fresh()->scan_status);
            $this->assertSame(1, Attachment::count());
        }
    }
}
