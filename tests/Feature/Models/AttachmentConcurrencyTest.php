<?php

namespace Tests\Feature\Models;

use App\Models\Attachment;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AttachmentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_actors_cannot_both_take_the_last_request_slot(): void
    {
        $request = WorkRequest::factory()->create();
        $actors = [$request->submitter, User::factory()->operator()->create()];
        $workers = [];
        $attachmentRoot = storage_path('framework/testing/disks/attachment-concurrency-'.Str::uuid());
        File::ensureDirectoryExists($attachmentRoot);
        DB::beginTransaction();
        DB::table('work_requests')->where('id', $request->id)->lockForUpdate()->first();
        try {
            foreach ($actors as $actor) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/register-attachment-worker.php')], base_path());
                $worker->setInput(json_encode(['database' => config('database.connections.mysql'), 'app_key' => config('app.key'),
                    'actor' => $actor->id, 'request' => $request->id, 'attachment_root' => $attachmentRoot], JSON_THROW_ON_ERROR)."\n");
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = 0;
                foreach ($workers as $worker) {
                    $ready += str_contains($worker->getOutput(), 'LOCKING') ? 1 : 0;
                }
                if ($ready === 2) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            foreach ($workers as $worker) {
                $this->assertStringContainsString('LOCKING', $worker->getOutput(), $worker->getErrorOutput());
                $this->assertStringNotContainsString('RESULT:', $worker->getOutput());
            }
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                preg_match('/RESULT:(.+)/', $worker->getOutput(), $match);
                $result = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
                if ($result['status'] === 'rejected') {
                    $this->assertSame(['file'], $result['fields']);
                }
            }
            sort($statuses);
            $this->assertSame(['registered', 'rejected'], $statuses);
            $this->assertSame(1, Attachment::count());
            $this->assertNotNull(Attachment::sole()->uploaded_by);
            config(['filesystems.disks.attachment_concurrency' => ['driver' => 'local', 'root' => $attachmentRoot]]);
            $this->assertTrue(Storage::disk('attachment_concurrency')->exists(Attachment::sole()->storage_path));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            File::deleteDirectory($attachmentRoot);
        }
    }
}
