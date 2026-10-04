<?php

namespace Tests\Feature\Models;

use App\Actions\CreateAttachmentLink;
use App\Actions\RegisterAttachment;
use App\Models\Attachment;
use App\Models\WorkRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\TestCase;

class AttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, resource> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('attachments');
        Storage::fake('public');
    }

    private function file(string $name = '고객 원본.secret.txt', string $contents = "private attachment bytes\n"): UploadedFile
    {
        $stream = tmpfile();
        fwrite($stream, $contents);
        $this->files[] = $stream;

        return new UploadedFile(stream_get_meta_data($stream)['uri'], $name, 'text/plain', UPLOAD_ERR_OK, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            fclose($file);
        }
        parent::tearDown();
    }

    public function test_registration_uses_unique_opaque_private_paths_and_preserves_exact_bytes(): void
    {
        $request = WorkRequest::factory()->create();
        $action = new RegisterAttachment;
        $first = $action->handle($request->submitter, $request, $this->file(contents: "first bytes\n"));
        $second = $action->handle($request->submitter, $request, $this->file(contents: "second bytes\n"));
        foreach ([$first, $second] as $attachment) {
            $this->assertSame('attachments', $attachment->storage_disk);
            $this->assertMatchesRegularExpression('~^objects/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$~', $attachment->storage_path);
            $this->assertStringNotContainsString('고객', $attachment->storage_path);
            $this->assertStringNotContainsString('.txt', $attachment->storage_path);
            Storage::disk('attachments')->assertExists($attachment->storage_path);
            $this->assertSame('private', Storage::disk('attachments')->getVisibility($attachment->storage_path));
            Storage::disk('public')->assertMissing($attachment->storage_path);
        }
        $this->assertNotSame($first->storage_path, $second->storage_path);
        $this->assertSame("first bytes\n", Storage::disk('attachments')->get($first->storage_path));
        $this->assertSame("second bytes\n", Storage::disk('attachments')->get($second->storage_path));
    }

    public function test_legacy_link_has_no_invented_storage_location(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        $this->assertNull($link->storage_disk);
        $this->assertNull($link->storage_path);
        Storage::disk('attachments')->assertDirectoryEmpty('/');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidDisks(): array
    {
        return ['missing' => [null], 'blank' => [''], 'unknown' => ['unknown'],
            'public' => ['public'], 'unspecified visibility' => ['local'], 'too long' => [str_repeat('a', 65)]];
    }

    #[DataProvider('invalidDisks')]
    public function test_invalid_or_public_disk_configuration_fails_closed(mixed $disk): void
    {
        config(['attachments.disk' => $disk]);
        $request = WorkRequest::factory()->create();
        try {
            (new RegisterAttachment)->handle($request->submitter, $request, $this->file());
            $this->fail('Unsafe storage configuration accepted');
        } catch (ValidationException $exception) {
            $this->assertSame(['file'], array_keys($exception->errors()));
            $this->assertSame(0, Attachment::count());
            Storage::disk('attachments')->assertDirectoryEmpty('/');
            Storage::disk('public')->assertDirectoryEmpty('/');
        }
    }

    public function test_database_failure_after_storage_removes_file_and_rolls_back_link(): void
    {
        Attachment::updating(function (Attachment $attachment): void {
            if ($attachment->storage_path !== null) {
                throw new RuntimeException('Synthetic database-stage failure');
            }
        });
        $request = WorkRequest::factory()->create();
        try {
            (new RegisterAttachment)->handle($request->submitter, $request, $this->file());
            $this->fail('Database failure not raised');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic database-stage failure', $exception->getMessage());
            $this->assertSame(0, Attachment::count());
            Storage::disk('attachments')->assertDirectoryEmpty('/');
        }
    }

    public function test_storage_write_failure_rolls_back_link(): void
    {
        config(['attachments.disk' => 'broken_attachment', 'filesystems.disks.broken_attachment' => [
            'driver' => 'local', 'root' => base_path('composer.json'), 'visibility' => 'private', 'throw' => true,
        ]]);
        $request = WorkRequest::factory()->create();
        try {
            (new RegisterAttachment)->handle($request->submitter, $request, $this->file());
            $this->fail('Storage write failure accepted');
        } catch (ValidationException $exception) {
            $this->assertSame(['file'], array_keys($exception->errors()));
            $this->assertSame(0, Attachment::count());
        }
    }

    public function test_storage_columns_are_paired_unique_and_immutable(): void
    {
        $request = WorkRequest::factory()->create();
        $attachment = (new RegisterAttachment)->handle($request->submitter, $request, $this->file());
        foreach ([['storage_disk' => null], ['storage_path' => null]] as $values) {
            try {
                DB::table('attachments')->where('id', $attachment->id)->update($values);
                $this->fail('Partial storage identity accepted');
            } catch (QueryException) {
                $this->assertSame('attachments', $attachment->fresh()->storage_disk);
                $this->assertNotNull($attachment->fresh()->storage_path);
            }
        }
        $row = $attachment->fresh()->getAttributes();
        unset($row['id']);
        $this->expectException(QueryException::class);
        DB::table('attachments')->insert($row);
    }

    public function test_new_metadata_cannot_be_added_without_stored_content_even_via_sql(): void
    {
        $request = WorkRequest::factory()->create();
        $link = (new CreateAttachmentLink)->handle($request->submitter, $request);
        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $link->id)->update(['original_name' => 'missing.txt',
            'size_bytes' => 1, 'mime_type' => 'text/plain', 'uploaded_by' => $request->submitted_by,
            'uploaded_at' => now()]);
    }
}
