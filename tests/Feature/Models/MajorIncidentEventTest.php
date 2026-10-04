<?php

namespace Tests\Feature\Models;

use App\Actions\RecordMajorIncidentEvent;
use App\Enums\MajorIncidentEventType;
use App\Enums\MajorIncidentResponseStatus;
use App\Enums\NotificationType;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\MajorIncidentEvent;
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

class MajorIncidentEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_records_an_on_time_first_response_and_customer_is_notified_without_detail_text(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 10:30:00');
        $company = Company::factory()->create();
        $customer = User::factory()->customerAdmin()->for($company)->create();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->for($company)->create([
            'submitted_by' => $customer->id,
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);

        $event = (new RecordMajorIncidentEvent)->handle(
            $operator,
            $incident,
            MajorIncidentEventType::FirstResponse,
            '서비스 상태 확인 및 최초 안내',
            '고객 담당자에게 영향 범위와 다음 안내 예정 시각을 전달했습니다.',
            CarbonImmutable::parse('2026-10-04 09:45:00'),
        );

        $incident->unsetRelation('firstResponseEvent');
        $this->assertSame(MajorIncidentResponseStatus::Met, $incident->majorIncidentFirstResponseTargetStatus());
        $this->assertSame('2026-10-04 09:45:00', $event->occurred_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('notification_deliveries', [
            'work_request_id' => $incident->id,
            'notification_type' => NotificationType::MajorIncidentUpdated->value,
            'notifiable_id' => $customer->id,
        ]);
        $delivery = DB::table('notification_deliveries')->where('notification_type', NotificationType::MajorIncidentUpdated->value)->first();
        $this->assertNotNull($delivery);
        $this->assertStringNotContainsString('영향 범위', (string) $delivery->delivery_data);
        $this->assertStringNotContainsString('60분', (string) $delivery->delivery_data);
        $this->assertStringNotContainsString('기한', (string) $delivery->delivery_data);
        $this->assertStringNotContainsString('보장', (string) $delivery->delivery_data);
        $this->assertStringContainsString((string) $event->id, (string) $delivery->delivery_data);
    }

    public function test_actual_first_response_time_determines_target_status_and_exact_boundary_is_met(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 12:00:00');
        $operator = User::factory()->operator()->create();
        $exact = WorkRequest::factory()->urgent()->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);
        $late = WorkRequest::factory()->urgent()->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);

        foreach ([[$exact, '10:00:00'], [$late, '10:00:01']] as [$incident, $time]) {
            (new RecordMajorIncidentEvent)->handle(
                $operator,
                $incident,
                MajorIncidentEventType::FirstResponse,
                '최초 응답',
                '상태를 확인하고 고객에게 안내했습니다.',
                CarbonImmutable::parse('2026-10-04 '.$time),
            );
        }

        $this->assertSame(MajorIncidentResponseStatus::Met, $exact->majorIncidentFirstResponseTargetStatus());
        $this->assertSame(MajorIncidentResponseStatus::Late, $late->majorIncidentFirstResponseTargetStatus());
        $this->assertFalse($late->hasMajorIncidentFirstResponseTargetElapsed());
    }

    public function test_only_one_first_response_is_allowed_by_action_and_database(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 11:00:00');
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);
        $action = new RecordMajorIncidentEvent;
        $action->handle($operator, $incident, MajorIncidentEventType::FirstResponse, '첫 응답', '첫 응답 내용', CarbonImmutable::parse('2026-10-04 09:30:00'));

        try {
            $action->handle($operator, $incident, MajorIncidentEventType::FirstResponse, '중복 응답', '중복 응답 내용', CarbonImmutable::parse('2026-10-04 09:40:00'));
            $this->fail('중복 최초 응답이 거부되어야 합니다.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event_type', $exception->errors());
        }

        $this->expectException(QueryException::class);
        MajorIncidentEvent::query()->create([
            'company_id' => $incident->company_id,
            'work_request_id' => $incident->id,
            'recorded_by' => $operator->id,
            'event_type' => MajorIncidentEventType::FirstResponse,
            'summary' => '직접 중복',
            'details' => '데이터베이스 고유 제약 검사',
            'occurred_at' => '2026-10-04 09:50:00',
        ]);
    }

    public function test_customer_and_inactive_operator_cannot_record_history_and_closed_or_regular_requests_reject_it(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 11:00:00');
        $customer = User::factory()->customerAdmin()->create();
        $inactiveOperator = User::factory()->operator()->inactive()->create();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->for($customer->company)->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);
        $payload = [MajorIncidentEventType::ResponseUpdate, '진행', '진행 내용을 기록합니다.', CarbonImmutable::parse('2026-10-04 10:00:00')];

        foreach ([$customer, $inactiveOperator] as $actor) {
            try {
                (new RecordMajorIncidentEvent)->handle($actor, $incident, ...$payload);
                $this->fail('기록 권한이 없는 사용자입니다.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        foreach ([
            WorkRequest::factory()->create(),
            WorkRequest::factory()->urgent()->create(['status' => WorkRequestStatus::Completed]),
        ] as $notCurrentIncident) {
            try {
                (new RecordMajorIncidentEvent)->handle($operator, $notCurrentIncident, ...$payload);
                $this->fail('현재 주요 장애가 아닌 요청입니다.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_event_time_and_content_are_validated_against_the_incident(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 11:00:00');
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);

        foreach ([
            ['', '내용', CarbonImmutable::parse('2026-10-04 10:00:00'), 'summary'],
            ['요약', ' ', CarbonImmutable::parse('2026-10-04 10:00:00'), 'details'],
            ['요약', '내용', CarbonImmutable::parse('2026-10-04 08:59:59'), 'occurred_at'],
            ['요약', '내용', CarbonImmutable::parse('2026-10-04 11:00:01'), 'occurred_at'],
        ] as [$summary, $details, $occurredAt, $field]) {
            try {
                (new RecordMajorIncidentEvent)->handle($operator, $incident, MajorIncidentEventType::ResponseUpdate, $summary, $details, $occurredAt);
                $this->fail('잘못된 장애 이력이 거부되어야 합니다.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_history_is_immutable_through_model_and_database(): void
    {
        Notification::fake();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create();
        $event = (new RecordMajorIncidentEvent)->handle(
            $operator,
            $incident,
            MajorIncidentEventType::CustomerConsultation,
            '고객 협의 완료',
            '복구 확인 기준과 다음 안내 시각을 합의했습니다.',
            now(),
        );

        try {
            $event->update(['summary' => '변경']);
            $this->fail('모델 수정이 거부되어야 합니다.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        foreach (['update major_incident_events set summary = ? where id = ?', 'delete from major_incident_events where id = ?'] as $index => $sql) {
            try {
                $bindings = $index === 0 ? ['직접 변경', $event->id] : [$event->id];
                DB::statement($sql, $bindings);
                $this->fail('데이터베이스 변경이 거부되어야 합니다.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_database_rejects_history_for_a_regular_request_or_customer_recorder(): void
    {
        $operator = User::factory()->operator()->create();
        $customer = User::factory()->customerUser()->create();
        $regular = WorkRequest::factory()->create();
        $incident = WorkRequest::factory()->urgent()->for($customer->company)->create();

        foreach ([[$regular, $operator], [$incident, $customer]] as [$request, $recorder]) {
            try {
                MajorIncidentEvent::query()->create([
                    'company_id' => $request->company_id,
                    'work_request_id' => $request->id,
                    'recorded_by' => $recorder->id,
                    'event_type' => MajorIncidentEventType::ResponseUpdate,
                    'summary' => '직접 기록',
                    'details' => '데이터베이스 기록 경계 검사',
                    'occurred_at' => now(),
                ]);
                $this->fail('잘못된 장애 이력이 데이터베이스에서 거부되어야 합니다.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }
}
