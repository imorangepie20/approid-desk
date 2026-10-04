<?php

namespace Tests\Feature\Models;

use App\Actions\CompleteMajorIncidentRollback;
use App\Actions\StartMajorIncidentRollback;
use App\Enums\MajorIncidentRollbackOutcome;
use App\Enums\NotificationType;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\MajorIncidentRollback;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class MajorIncidentRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_starts_and_completes_rollback_with_two_safe_customer_notifications(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 11:00:00');
        $company = Company::factory()->create();
        $customer = User::factory()->customerAdmin()->for($company)->create();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->for($company)->create([
            'submitted_by' => $customer->id,
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);

        $rollback = (new StartMajorIncidentRollback)->handle(
            $operator,
            $incident,
            'API release-42',
            '이전 이미지로 전환합니다.',
            '오류율과 핵심 경로를 확인합니다.',
            CarbonImmutable::parse('2026-10-04 10:00:00'),
            true,
        );
        $completed = (new CompleteMajorIncidentRollback)->handle(
            $operator,
            $incident,
            $rollback,
            MajorIncidentRollbackOutcome::Succeeded,
            '이전 버전 복구 확인',
            '오류율이 정상 범위이며 핵심 경로가 통과했습니다.',
            CarbonImmutable::parse('2026-10-04 10:30:00'),
            true,
        );

        $this->assertSame(MajorIncidentRollbackOutcome::Succeeded, $completed->outcome);
        $this->assertSame($operator->id, $completed->completed_by);
        $deliveries = DB::table('notification_deliveries')
            ->where('notification_type', NotificationType::MajorIncidentRollbackUpdated->value)
            ->where('notifiable_id', $customer->id)
            ->orderBy('created_at')
            ->get();
        $this->assertCount(4, $deliveries);
        $payloads = $deliveries->pluck('delivery_data')->implode(' ');
        $this->assertStringContainsString((string) $rollback->id, $payloads);
        $this->assertStringContainsString('started', $payloads);
        $this->assertStringContainsString('completed', $payloads);
        $this->assertStringNotContainsString('이전 이미지', $payloads);
        $this->assertStringNotContainsString('오류율이 정상', $payloads);
    }

    public function test_only_one_active_rollback_is_allowed_and_a_new_one_can_start_after_completion(): void
    {
        Notification::fake();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create();
        $action = new StartMajorIncidentRollback;
        $first = $action->handle($operator, $incident, '첫 대상', '첫 계획', '첫 검증', now(), true);

        try {
            $action->handle($operator, $incident, '중복 대상', '중복 계획', '중복 검증', now(), true);
            $this->fail('진행 중인 롤백의 중복 시작이 거부되어야 합니다.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rollback_target', $exception->errors());
        }

        (new CompleteMajorIncidentRollback)->handle(
            $operator,
            $incident,
            $first,
            MajorIncidentRollbackOutcome::Failed,
            '복구 실패',
            '검증 기준을 충족하지 못했습니다.',
            now(),
            true,
        );
        $second = $action->handle($operator, $incident, '두 번째 대상', '두 번째 계획', '두 번째 검증', now(), true);
        $this->assertTrue($second->isActive());
        $this->assertSame(2, MajorIncidentRollback::query()->count());
    }

    public function test_customer_inactive_operator_regular_and_closed_request_cannot_start_rollback(): void
    {
        Notification::fake();
        $customer = User::factory()->customerAdmin()->create();
        $inactiveOperator = User::factory()->operator()->inactive()->create();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->for($customer->company)->create();

        foreach ([[$customer, $incident], [$inactiveOperator, $incident], [$operator, WorkRequest::factory()->create()], [$operator, WorkRequest::factory()->urgent()->create(['status' => WorkRequestStatus::Completed])]] as [$actor, $request]) {
            try {
                (new StartMajorIncidentRollback)->handle($actor, $request, '대상', '계획', '검증', now(), true);
                $this->fail('롤백 시작 권한이 없는 조건입니다.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_started_rollback_can_be_completed_after_request_becomes_terminal_but_only_once(): void
    {
        Notification::fake();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create();
        $rollback = (new StartMajorIncidentRollback)->handle($operator, $incident, '배포 버전', '전환 계획', '검증 계획', now(), true);
        DB::table('work_requests')->where('id', $incident->id)->update([
            'status' => WorkRequestStatus::Cancelled->value,
            'updated_at' => now(),
        ]);

        (new CompleteMajorIncidentRollback)->handle(
            $operator,
            $incident,
            $rollback,
            MajorIncidentRollbackOutcome::Aborted,
            '요청 취소로 중단',
            '추가 변경 없이 중단 상태를 확인했습니다.',
            now(),
            true,
        );

        $this->expectException(AuthorizationException::class);
        (new CompleteMajorIncidentRollback)->handle(
            $operator,
            $incident,
            $rollback,
            MajorIncidentRollbackOutcome::Succeeded,
            '중복 결과',
            '두 번째 확정은 허용하지 않습니다.',
            now(),
            true,
        );
    }

    public function test_start_and_completion_fields_and_times_are_validated(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 11:00:00');
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);

        foreach ([
            ['', '계획', '검증', CarbonImmutable::parse('2026-10-04 10:00:00'), true, 'rollback_target'],
            ['대상', '', '검증', CarbonImmutable::parse('2026-10-04 10:00:00'), true, 'rollback_plan'],
            ['대상', '계획', '', CarbonImmutable::parse('2026-10-04 10:00:00'), true, 'rollback_verification_plan'],
            ['대상', '계획', '검증', CarbonImmutable::parse('2026-10-04 08:59:59'), true, 'rollback_started_at'],
            ['대상', '계획', '검증', CarbonImmutable::parse('2026-10-04 11:00:01'), true, 'rollback_started_at'],
            ['대상', '계획', '검증', CarbonImmutable::parse('2026-10-04 10:00:00'), false, 'rollback_start_confirmed'],
        ] as [$target, $plan, $verification, $time, $confirmed, $field]) {
            try {
                (new StartMajorIncidentRollback)->handle($operator, $incident, $target, $plan, $verification, $time, $confirmed);
                $this->fail('잘못된 롤백 시작 입력이 거부되어야 합니다.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $rollback = (new StartMajorIncidentRollback)->handle($operator, $incident, '대상', '계획', '검증', CarbonImmutable::parse('2026-10-04 10:00:00'), true);
        foreach ([
            ['', '상세', CarbonImmutable::parse('2026-10-04 10:30:00'), true, 'rollback_result_summary'],
            ['요약', '', CarbonImmutable::parse('2026-10-04 10:30:00'), true, 'rollback_result_details'],
            ['요약', '상세', CarbonImmutable::parse('2026-10-04 09:59:59'), true, 'rollback_completed_at'],
            ['요약', '상세', CarbonImmutable::parse('2026-10-04 11:00:01'), true, 'rollback_completed_at'],
            ['요약', '상세', CarbonImmutable::parse('2026-10-04 10:30:00'), false, 'rollback_result_confirmed'],
        ] as [$summary, $details, $time, $confirmed, $field]) {
            try {
                (new CompleteMajorIncidentRollback)->handle($operator, $incident, $rollback, MajorIncidentRollbackOutcome::Succeeded, $summary, $details, $time, $confirmed);
                $this->fail('잘못된 롤백 결과 입력이 거부되어야 합니다.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_model_and_database_protect_identity_completion_and_deletion(): void
    {
        Notification::fake();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create();
        $rollback = (new StartMajorIncidentRollback)->handle($operator, $incident, '대상', '계획', '검증', now(), true);

        try {
            $rollback->update(['target' => '변경']);
            $this->fail('모델 수정이 거부되어야 합니다.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        foreach ([
            ['update major_incident_rollbacks set target = ? where id = ?', ['직접 변경', $rollback->id]],
            ['update major_incident_rollbacks set outcome = ? where id = ?', ['succeeded', $rollback->id]],
            ['delete from major_incident_rollbacks where id = ?', [$rollback->id]],
        ] as [$sql, $bindings]) {
            try {
                DB::statement($sql, $bindings);
                $this->fail('잘못된 직접 변경이 데이터베이스에서 거부되어야 합니다.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_database_rejects_regular_request_customer_actor_and_initial_result(): void
    {
        $operator = User::factory()->operator()->create();
        $customer = User::factory()->customerUser()->create();
        $regular = WorkRequest::factory()->create();
        $incident = WorkRequest::factory()->urgent()->for($customer->company)->create();

        foreach ([
            [$regular, $operator, []],
            [$incident, $customer, []],
            [$incident, $operator, [
                'outcome' => 'succeeded',
                'result_summary' => '결과',
                'result_details' => '상세',
                'completed_by' => $operator->id,
                'completed_at' => now(),
            ]],
        ] as [$request, $actor, $completion]) {
            try {
                DB::table('major_incident_rollbacks')->insert(array_merge([
                    'company_id' => $request->company_id,
                    'work_request_id' => $request->id,
                    'started_by' => $actor->id,
                    'target' => '직접 대상',
                    'plan' => '직접 계획',
                    'verification_plan' => '직접 검증',
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $completion));
                $this->fail('잘못된 롤백 시작이 데이터베이스에서 거부되어야 합니다.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }
}
