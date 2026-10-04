<?php

namespace Tests\Feature\Models;

use App\Enums\NotificationDeliveryFailure;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryRetry;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NotificationRetryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_concurrent_manual_retries_queue_exactly_one_audited_job(): void
    {
        $request = WorkRequest::factory()->create();
        $recipient = User::factory()->customerAdmin()->for($request->company)->create();
        $operator = User::factory()->operator()->create();
        $delivery = NotificationDelivery::query()->create([
            'event_key' => hash('sha256', 'concurrent-manual-retry'),
            'company_id' => $request->company_id,
            'work_request_id' => $request->id,
            'notification_type' => NotificationType::EstimateSubmitted,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id,
            'channel' => 'mail',
            'delivery_data' => [
                'request_title' => $request->title,
                'message' => '검토할 새 견적이 제출되었습니다.',
                'action_url' => '/requests/'.$request->id,
                'context' => ['estimate_version_id' => 923],
            ],
        ]);
        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Failed,
            'failed_at' => now(),
            'failure_code' => NotificationDeliveryFailure::MailTransport,
        ])->save();
        $workers = [];

        DB::beginTransaction();
        DB::table('users')->where('id', $operator->id)->lockForUpdate()->first();
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/retry-notification-worker.php')], base_path());
                $worker->setTimeout(20);
                $worker->setInput(json_encode([
                    'database' => config('database.connections.mysql'),
                    'app_key' => config('app.key'),
                    'actor' => $operator->id,
                    'delivery' => $delivery->id,
                    'lock_table' => 'users',
                ], JSON_THROW_ON_ERROR)."\n");
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 8;
            do {
                if (count(array_filter($workers, fn (Process $worker): bool => str_contains($worker->getOutput(), 'LOCKING'))) === 2) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            foreach ($workers as $worker) {
                $this->assertStringContainsString('LOCKING', $worker->getOutput(), $worker->getErrorOutput());
                $this->assertStringNotContainsString('RESULT:', $worker->getOutput());
            }

            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                preg_match('/RESULT:(.+)/', $worker->getOutput(), $match);
                $results[] = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            }

            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['queued', 'rejected'], $statuses);
            $queued = collect($results)->firstWhere('status', 'queued');
            $rejected = collect($results)->firstWhere('status', 'rejected');
            $this->assertSame(1, $queued['jobs']);
            $this->assertSame(['delivery'], $rejected['fields']);
            $this->assertSame(NotificationDeliveryStatus::Pending, $delivery->fresh()->status);
            $this->assertNull($delivery->fresh()->sent_at);
            $this->assertSame(1, NotificationDeliveryRetry::count());
            $this->assertSame($operator->id, NotificationDeliveryRetry::sole()->requested_by);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }
}
