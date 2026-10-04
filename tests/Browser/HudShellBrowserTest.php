<?php

namespace Tests\Browser;

use App\Actions\AdjustContractMonth;
use App\Actions\ApproveEstimateVersion;
use App\Actions\CancelWorkLogUsage;
use App\Actions\CompleteMajorIncidentRollback;
use App\Actions\ConfirmNonBillableWorkLog;
use App\Actions\ConfirmWorkLog;
use App\Actions\ProvideContractMonth;
use App\Actions\RecordMajorIncidentEvent;
use App\Actions\SaveWorkLogDraft;
use App\Actions\SendMonthTransitionReminders;
use App\Actions\SendWeeklyProgressReports;
use App\Actions\StartMajorIncidentRollback;
use App\Enums\MajorIncidentEventType;
use App\Enums\MajorIncidentRollbackOutcome;
use App\Enums\NotificationDeliveryFailure;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationType;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\MonthAdjustment;
use App\Models\MonthClosure;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryRetry;
use App\Models\ServiceContract;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class HudShellBrowserTest extends TestCase
{
    use BuildsContractTime, DatabaseMigrations;

    public function test_month_management_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        $operator = User::factory()->operator()->create();
        $contract = ServiceContract::factory()->signed()->create(['starts_on' => today()->subMonths(2)->startOfMonth()]);
        $month = (new ProvideContractMonth)->handle($operator, $contract, today()->subMonth()->startOfMonth()->toDateString(), 100);
        $password = Str::random(32);
        $operator->forceFill(['password' => $password])->save();
        $this->withBrowserServer(function () use ($operator, $password, $month): void {
            $browser = new Process(['node', base_path('tests/Browser/month-management.cjs')], base_path());
            $browser->setTimeout(180);
            $browser->setInput(json_encode(['operator' => $operator->email, 'password' => $password,
                'month' => $month->id, 'entry' => $month->entries()->sole()->id], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
        });
        $this->assertSame('closed', $month->fresh()->status);
        $this->assertSame(2, $month->entries()->count());
        $this->assertSame(100, MonthClosure::query()->sole()->totals['available']);
        $this->assertSame(30, MonthAdjustment::query()->sole()->minutes);
    }

    public function test_monthly_usage_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture(120, 60);
        $request->update(['title' => '월 사용내역 브라우저 검증 '.str_repeat('긴 요청 제목 ', 8)]);
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Browser usage');
        foreach ([[30, true], [10, true], [20, false]] as [$minutes, $billable]) {
            $log = (new SaveWorkLogDraft)->handle($operator, $request, ['worked_on' => today()->toDateString(),
                'minutes' => $minutes, 'description' => $billable ? '차감 작업' : '내부 비차감 작업',
                'is_billable' => $billable, 'non_billable_reason' => $billable ? null : '내부 귀책 사유']);
            ($billable ? new ConfirmWorkLog : new ConfirmNonBillableWorkLog)->handle($operator, $log, 1);
            if ($minutes === 30) {
                (new CancelWorkLogUsage)->handle($operator, $log, '내부 취소 사유');
            }
        }
        $provided = $month->entries()->where('type', TimeLedgerType::Provided)->sole();
        (new AdjustContractMonth)->handle($operator, $month, $provided, TimeLedgerType::AdjustIncrease, 10, '내부 조정 사유', (string) Str::uuid());
        (new AdjustContractMonth)->handle($operator, $month, $provided, TimeLedgerType::AdjustDecrease, 5, '내부 조정 사유', (string) Str::uuid());
        $foreign = ServiceContract::factory()->signed()->create();
        (new ProvideContractMonth)->handle($operator, $foreign, today()->startOfMonth()->toDateString(), 999);
        $customer = User::factory()->customerUser()->for($request->company)->create();
        $password = Str::random(32);
        foreach ([$operator, $admin, $customer] as $user) {
            $user->forceFill(['password' => $password])->save();
        }
        $this->withBrowserServer(function () use ($operator, $admin, $customer, $password, $month, $foreign): void {
            $browser = new Process(['node', base_path('tests/Browser/monthly-usage.cjs')], base_path());
            $browser->setTimeout(180);
            $browser->setInput(json_encode(['operator' => $operator->email, 'admin' => $admin->email,
                'customer' => $customer->email, 'password' => $password, 'month_id' => $month->id,
                'month' => $month->month->format('Y-m'), 'company' => $month->company_id,
                'foreign_company' => $foreign->company->name], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertStringContainsString('MONTHLY_USAGE_BROWSER_PASS', $browser->getOutput());
        });
        $this->assertSame(9, $month->entries()->count());
        $this->assertSame(3, WorkLog::count());
    }

    public function test_work_log_entry_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Browser work log');
        $password = Str::random(32);
        $operator->forceFill(['password' => $password])->save();
        $this->withBrowserServer(function () use ($request, $operator, $password): void {
            $browser = new Process(['node', base_path('tests/Browser/work-log-entry.cjs')], base_path());
            $browser->setTimeout(120);
            $browser->setInput(json_encode(['operator' => $operator->email, 'password' => $password, 'request' => $request->id], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
        });
        $this->assertSame(35, TimeLedgerEntry::where('type', 'usage')->sole()->minutes);
        $this->assertSame(2, WorkLog::where('status', 'confirmed')->count());
    }

    public function test_hud_shell_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        [$request, $operator, $admin, , $estimate] = $this->timeFixture(1200);
        $customer = User::factory()->customerUser()->for($request->company)->create();
        $password = Str::random(32);
        foreach ([$operator, $admin, $customer] as $user) {
            $user->forceFill(['password' => $password])->save();
        }
        $draftRequest = WorkRequest::factory()->for($request->company)->create();
        $reviewRequest = WorkRequest::factory()->for($request->company)->create([
            'status' => WorkRequestStatus::AwaitingReview,
            'title' => '검수 대기 브라우저 확인 '.str_repeat('긴 제목 검증 ', 8),
        ]);

        $this->withBrowserServer(function () use ($request, $operator, $admin, $customer, $password, $estimate, $draftRequest, $reviewRequest): void {
            $browser = new Process(['node', base_path('tests/Browser/hud-shell.cjs')], base_path());
            $browser->setTimeout(210);
            $browser->setInput(json_encode([
                'operator' => $operator->email, 'admin' => $admin->email, 'customer' => $customer->email, 'password' => $password,
                'request' => $request->id, 'estimate' => $estimate->id, 'company' => $request->company_id,
                'contract' => $request->service_contract_id, 'project' => $request->project_id, 'draft_request' => $draftRequest->id,
                'review_request' => $reviewRequest->id,
            ], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertStringContainsString('HUD_BROWSER_PASS', $browser->getOutput());
        });
    }

    public function test_terminal_actions_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        [$request, $operator, $admin] = $this->timeFixture(1200);
        $password = Str::random(32);
        foreach ([$operator, $admin] as $user) {
            $user->forceFill(['password' => $password])->save();
        }
        $terminalRequests = [];
        foreach (['cancel' => '브라우저 취소 정산', 'complete' => '브라우저 완료 정산'] as $key => $title) {
            $terminalRequest = WorkRequest::factory()->for($request->company)->create([
                'service_contract_id' => $request->service_contract_id,
                'status' => WorkRequestStatus::AwaitingApproval,
                'title' => $title,
            ]);
            $terminalEstimate = $this->timeEstimate($operator, $terminalRequest);
            (new ApproveEstimateVersion)->handle($admin, $terminalEstimate, (string) Str::uuid(),
                ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Browser terminal test');
            $terminalRequests[$key] = $terminalRequest;
        }

        $this->withBrowserServer(function () use ($operator, $admin, $password, $terminalRequests): void {
            $browser = new Process(['node', base_path('tests/Browser/terminal-actions.cjs')], base_path());
            $browser->setTimeout(120);
            $browser->setInput(json_encode([
                'operator' => $operator->email, 'admin' => $admin->email, 'password' => $password,
                'cancel_request' => $terminalRequests['cancel']->id,
                'complete_request' => $terminalRequests['complete']->id,
            ], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertStringContainsString('TERMINAL_BROWSER_PASS', $browser->getOutput());
        });
        $this->assertSame(WorkRequestStatus::Cancelled, $terminalRequests['cancel']->fresh()->status);
        $this->assertSame(WorkRequestStatus::InProgress, $terminalRequests['complete']->fresh()->status);
        $this->assertTrue($terminalRequests['complete']->statusChanges()->latest('id')->firstOrFail()->is_free_rework);
        foreach ($terminalRequests as $terminalRequest) {
            $this->assertSame(60, (int) TimeLedgerEntry::where('work_request_id', $terminalRequest->id)
                ->where('type', 'release')->sum('minutes'));
        }
    }

    public function test_reestimate_reservation_replacement_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        [$request, $operator, $admin, , $estimate] = $this->timeFixture(1200);
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Browser replacement setup');
        $password = Str::random(32);
        foreach ([$operator, $admin] as $user) {
            $user->forceFill(['password' => $password])->save();
        }

        $this->withBrowserServer(function () use ($request, $operator, $admin, $password): void {
            $browser = new Process(['node', base_path('tests/Browser/reestimate-actions.cjs')], base_path());
            $browser->setTimeout(120);
            $browser->setInput(json_encode([
                'operator' => $operator->email,
                'admin' => $admin->email,
                'password' => $password,
                'request' => $request->id,
            ], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertStringContainsString('REESTIMATE_BROWSER_PASS', $browser->getOutput());
        });

        $this->assertSame(WorkRequestStatus::Queued, $request->fresh()->status);
        $this->assertSame(2, $request->estimateVersions()->count());
        $this->assertSame($request->latestEstimateVersion()->firstOrFail()->id, $request->fresh()->approved_estimate_version_id);
        $this->assertSame(60, TimeLedgerEntry::where('work_request_id', $request->id)
            ->where('source_type', 'estimate_replacement')->sole()->minutes);
        $this->assertSame(90, TimeLedgerEntry::where('work_request_id', $request->id)
            ->where('type', 'reserve')->latest('id')->firstOrFail()->minutes);
    }

    public function test_month_transition_notification_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture(1200);
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Browser month transition setup');
        DB::table('notifications')->delete();
        $password = Str::random(32);
        $operator->forceFill(['password' => $password])->save();
        $this->travelTo($month->month->copy()->endOfMonth()->subDay());
        $this->assertSame(1, (new SendMonthTransitionReminders)->handle(today()));
        $notification = $operator->fresh()->unreadNotifications()->sole();
        $this->travelBack();

        $this->withBrowserServer(function () use ($request, $operator, $password): void {
            $browser = new Process(['node', base_path('tests/Browser/month-transition-notification.cjs')], base_path());
            $browser->setTimeout(120);
            $browser->setInput(json_encode([
                'operator' => $operator->email,
                'password' => $password,
                'request' => $request->id,
                'title' => $request->title,
            ], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertStringContainsString('MONTH_TRANSITION_BROWSER_PASS', $browser->getOutput());
        });

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_weekly_progress_report_notification_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        $this->travelTo('2026-10-05 09:15:00');
        $request = WorkRequest::factory()->create([
            'title' => '주간 진행 보고 브라우저 검증',
            'requested_at' => '2026-10-01 10:00:00',
            'registered_at' => '2026-10-01 10:00:00',
        ]);
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $password = Str::random(32);
        $admin->forceFill(['password' => $password])->save();
        $this->assertSame(1, (new SendWeeklyProgressReports)->handle(today()));
        $notification = $admin->fresh()->unreadNotifications()->sole();
        $this->travelBack();

        $this->withBrowserServer(function () use ($request, $admin, $password): void {
            $browser = new Process(['node', base_path('tests/Browser/weekly-progress-report.cjs')], base_path());
            $browser->setTimeout(150);
            $browser->setInput(json_encode([
                'admin' => $admin->email,
                'password' => $password,
                'company' => $request->company->name,
            ], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertStringContainsString('WEEKLY_PROGRESS_REPORT_BROWSER_PASS', $browser->getOutput());
        });

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_failed_notification_delivery_retry_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        $request = WorkRequest::factory()->create([
            'title' => '실패 알림 재시도 브라우저 검증 '.str_repeat('긴 요청 제목 ', 8),
        ]);
        $recipient = User::factory()->customerAdmin()->for($request->company)->create();
        $operator = User::factory()->operator()->create();
        $password = Str::random(32);
        $operator->forceFill(['password' => $password])->save();
        $delivery = NotificationDelivery::query()->create([
            'event_key' => hash('sha256', 'browser-failed-notification'),
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
                'context' => ['estimate_version_id' => 913],
            ],
        ]);
        $delivery->forceFill([
            'status' => NotificationDeliveryStatus::Failed,
            'attempt_count' => 4,
            'last_attempted_at' => now(),
            'failed_at' => now(),
            'failure_code' => NotificationDeliveryFailure::MailTransport,
        ])->save();

        $this->withBrowserServer(function () use ($operator, $password, $delivery): void {
            $browser = new Process(['node', base_path('tests/Browser/notification-deliveries.cjs')], base_path());
            $browser->setTimeout(180);
            $browser->setInput(json_encode([
                'operator' => $operator->email,
                'password' => $password,
                'delivery' => $delivery->id,
            ], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertStringContainsString('NOTIFICATION_DELIVERY_BROWSER_PASS', $browser->getOutput());
        });

        $delivery->refresh();
        $this->assertSame(NotificationDeliveryStatus::Sent, $delivery->status);
        $this->assertSame(5, $delivery->attempt_count);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame(1, NotificationDeliveryRetry::query()->count());
    }

    public function test_major_incident_priority_display_in_chromium(): void
    {
        $this->assertSame('testing', config('database.connections.mysql.database'));
        $operator = User::factory()->operator()->create();
        $customer = User::factory()->customerAdmin()->create();
        $project = $customer->company->projects()->create([
            'name' => '장애 우선순위 브라우저 프로젝트',
            'description' => '주요 업무 장애 표시 검증',
            'status' => 'active',
            'is_existing_site' => false,
            'source_code_secured' => false,
            'database_dump_secured' => false,
        ]);
        $now = now()->startOfMinute();
        $oldestRequestedAt = $now->copy()->subHours(2);
        $newerRequestedAt = $now->copy()->subMinutes(30);
        $oldest = WorkRequest::factory()->urgent()->for($customer->company)->for($project)->create([
            'submitted_by' => $customer->id,
            'title' => '오래된 주요 장애 '.str_repeat('결제 중단 ', 8),
            'requested_at' => $oldestRequestedAt,
            'registered_at' => $oldestRequestedAt,
        ]);
        $newer = WorkRequest::factory()->urgent()->for($customer->company)->for($project)->create([
            'submitted_by' => $customer->id,
            'title' => '나중에 접수된 주요 장애',
            'requested_at' => $newerRequestedAt,
            'registered_at' => $newerRequestedAt,
        ]);
        $regular = WorkRequest::factory()->for($customer->company)->for($project)->create([
            'submitted_by' => $customer->id,
            'title' => '가장 최신 일반 요청',
            'requested_at' => $now,
            'registered_at' => $now,
        ]);
        $completed = WorkRequest::factory()->urgent()->for($customer->company)->for($project)->create([
            'submitted_by' => $customer->id,
            'status' => WorkRequestStatus::Completed,
            'title' => '완료된 과거 장애',
            'requested_at' => $now->copy()->subHours(3),
            'registered_at' => $now->copy()->subHours(3),
        ]);
        (new RecordMajorIncidentEvent)->handle(
            $operator,
            $oldest,
            MajorIncidentEventType::CustomerConsultation,
            '고객 영향 범위 확인',
            '결제 중단 범위와 다음 안내 시각을 고객 담당자와 협의했습니다.',
            $oldestRequestedAt->copy()->addMinutes(30),
        );
        $previousRollback = (new StartMajorIncidentRollback)->handle(
            $operator,
            $oldest,
            '이전 API 이미지',
            '안정 버전 이미지로 트래픽을 전환합니다.',
            '오류율과 결제 핵심 경로를 확인합니다.',
            $oldestRequestedAt->copy()->addMinutes(40),
            true,
        );
        (new CompleteMajorIncidentRollback)->handle(
            $operator,
            $oldest,
            $previousRollback,
            MajorIncidentRollbackOutcome::Succeeded,
            '안정 버전 복구 확인',
            '오류율과 결제 핵심 경로가 정상 범위입니다.',
            $oldestRequestedAt->copy()->addMinutes(50),
            true,
        );
        $password = Str::random(32);
        foreach ([$operator, $customer] as $user) {
            $user->forceFill(['password' => $password])->save();
        }

        $this->withBrowserServer(function () use ($operator, $customer, $password, $oldest, $newer, $regular, $completed): void {
            $browser = new Process(['node', base_path('tests/Browser/major-incident-priority.cjs')], base_path());
            $browser->setTimeout(180);
            $browser->setInput(json_encode([
                'operator' => $operator->email,
                'customer' => $customer->email,
                'password' => $password,
                'oldest' => ['id' => $oldest->id, 'title' => $oldest->title, 'target' => $oldest->majorIncidentFirstResponseTargetAt()?->format('Y.m.d H:i')],
                'newer' => ['id' => $newer->id, 'title' => $newer->title, 'target' => $newer->majorIncidentFirstResponseTargetAt()?->format('Y.m.d H:i')],
                'regular' => ['id' => $regular->id, 'title' => $regular->title],
                'completed' => ['id' => $completed->id, 'title' => $completed->title],
            ], JSON_THROW_ON_ERROR));
            $browser->run();
            $this->assertTrue($browser->isSuccessful(), $browser->getOutput().$browser->getErrorOutput());
            $this->assertStringContainsString('MAJOR_INCIDENT_PRIORITY_BROWSER_PASS', $browser->getOutput());
        });
    }

    /** @param callable(): void $run */
    private function withBrowserServer(callable $run): void
    {
        $occupied = @fsockopen('127.0.0.1', 8787);
        if ($occupied !== false) {
            fclose($occupied);
        }
        $this->assertFalse($occupied, 'Browser test port 8787 is already occupied.');
        $server = new Process(['setsid', PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port=8787', '--no-reload'], base_path(), [
            'APP_ENV' => 'testing', 'APP_URL' => 'http://127.0.0.1:8787', 'DB_DATABASE' => 'testing',
            'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'desk_ui_audit', 'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'PHP_CLI_SERVER_WORKERS' => '4',
        ]);
        $server->setTimeout(240);
        $server->start();
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $socket = @fsockopen('127.0.0.1', 8787);
                if ($socket !== false) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($ready, $server->getErrorOutput());
            $run();
        } finally {
            if ($server->isRunning() && $server->getPid() !== null) {
                (new Process(['kill', '-TERM', '--', '-'.$server->getPid()]))->run();
            }
            // Allow ServeCommand's signal trap to reap its PHP server workers.
            $server->stop(15);
        }
    }
}
