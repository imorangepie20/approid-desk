<?php

namespace Tests\Feature\Models;

use App\Enums\IntakeChannel;
use App\Enums\MajorIncidentResponseStatus;
use App\Enums\UserRole;
use App\Enums\WorkRequestPriority;
use App\Enums\WorkRequestStatus;
use App\Enums\WorkRequestType;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_received_web_request_in_one_company_project(): void
    {
        $workRequest = WorkRequest::factory()->create();

        $this->assertTrue($workRequest->company->is($workRequest->project->company));
        $this->assertTrue($workRequest->company->is($workRequest->submitter->company));
        $this->assertSame(WorkRequestType::Feature, $workRequest->type);
        $this->assertSame(WorkRequestPriority::Normal, $workRequest->priority);
        $this->assertSame(IntakeChannel::Web, $workRequest->intake_channel);
        $this->assertSame(WorkRequestStatus::Received, $workRequest->status);
        $this->assertFalse($workRequest->is_urgent);
        $this->assertNotNull($workRequest->requested_at);
        $this->assertNotNull($workRequest->registered_at);
    }

    public function test_major_incident_scope_only_contains_open_urgent_requests_and_priority_scope_puts_them_first(): void
    {
        $incident = WorkRequest::factory()->urgent()->create([
            'requested_at' => '2026-10-01 09:00:00',
            'registered_at' => '2026-10-01 09:00:00',
        ]);
        $regular = WorkRequest::factory()->create([
            'requested_at' => '2026-10-03 12:00:00',
            'registered_at' => '2026-10-03 12:00:00',
        ]);
        $completedUrgent = WorkRequest::factory()->urgent()->create([
            'status' => WorkRequestStatus::Completed,
            'requested_at' => '2026-10-03 11:00:00',
            'registered_at' => '2026-10-03 11:00:00',
        ]);

        $this->assertTrue($incident->isMajorIncident());
        $this->assertFalse($regular->isMajorIncident());
        $this->assertFalse($completedUrgent->isMajorIncident());
        $this->assertSame([$incident->id], WorkRequest::query()->majorIncidents()->pluck('id')->all());
        $this->assertSame(
            [$incident->id, $regular->id, $completedUrgent->id],
            WorkRequest::query()->majorIncidentsFirst()->latest('requested_at')->latest('id')->pluck('id')->all(),
        );
    }

    public function test_major_incident_first_response_target_uses_the_actual_request_time_and_an_exact_sixty_minute_boundary(): void
    {
        $incident = WorkRequest::factory()->urgent()->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:40:00',
        ]);
        $regular = WorkRequest::factory()->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);
        $completedIncident = WorkRequest::factory()->urgent()->create([
            'status' => WorkRequestStatus::Completed,
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);

        $this->assertSame('2026-10-04 10:00:00', $incident->majorIncidentFirstResponseTargetAt()?->format('Y-m-d H:i:s'));
        $this->assertNull($regular->majorIncidentFirstResponseTargetAt());
        $this->assertFalse($incident->hasMajorIncidentFirstResponseTargetElapsed(CarbonImmutable::parse('2026-10-04 10:00:00')));
        $this->assertSame(MajorIncidentResponseStatus::Pending, $incident->majorIncidentFirstResponseTargetStatus(CarbonImmutable::parse('2026-10-04 10:00:00')));
        $this->assertTrue($incident->hasMajorIncidentFirstResponseTargetElapsed(CarbonImmutable::parse('2026-10-04 10:00:01')));
        $this->assertSame(MajorIncidentResponseStatus::Overdue, $incident->majorIncidentFirstResponseTargetStatus(CarbonImmutable::parse('2026-10-04 10:00:01')));
        $this->assertSame('2026-10-04 10:00:00', $completedIncident->majorIncidentFirstResponseTargetAt()?->format('Y-m-d H:i:s'));
        $this->assertFalse($completedIncident->hasMajorIncidentFirstResponseTargetElapsed(CarbonImmutable::parse('2026-10-04 11:00:00')));
        $this->assertSame(MajorIncidentResponseStatus::Unrecorded, $completedIncident->majorIncidentFirstResponseTargetStatus(CarbonImmutable::parse('2026-10-04 11:00:00')));
    }

    public function test_major_incident_response_target_is_never_turned_into_a_contract_commitment_automatically(): void
    {
        $withoutContract = WorkRequest::factory()->urgent()->create();
        $withSignedContract = WorkRequest::factory()->urgent()->withSignedContract()->create();

        $this->assertFalse($withoutContract->majorIncidentResponseTargetIsContractual());
        $this->assertFalse($withSignedContract->majorIncidentResponseTargetIsContractual());
        $this->assertNotNull($withSignedContract->serviceContract);
    }

    public function test_company_and_project_relationships_only_return_their_requests(): void
    {
        $company = Company::factory()->create();
        $project = Project::factory()->for($company)->create();
        $ownRequest = WorkRequest::factory()->for($company)->for($project)->create();
        WorkRequest::factory()->create();

        $this->assertCount(1, $company->workRequests);
        $this->assertTrue($company->workRequests->first()->is($ownRequest));
        $this->assertCount(1, $project->workRequests);
        $this->assertTrue($project->workRequests->first()->is($ownRequest));
    }

    public function test_request_cannot_reference_a_project_from_another_company(): void
    {
        $company = Company::factory()->create();
        $anotherCompanyProject = Project::factory()->create();

        $this->expectException(QueryException::class);

        WorkRequest::factory()->for($company)->for($anotherCompanyProject)->create();
    }

    public function test_operator_can_record_a_phone_request_with_source_and_summary(): void
    {
        $operator = User::factory()->operator()->create();
        $workRequest = WorkRequest::factory()->create([
            'submitted_by' => $operator->id,
            'intake_channel' => IntakeChannel::Phone,
            'source_reference' => '고객사 담당자 010-0000-0000 통화',
            'intake_summary' => '주문 엑셀 다운로드 기능을 요청함',
        ]);

        $this->assertSame(UserRole::Operator, $workRequest->submitter->role);
        $this->assertSame(IntakeChannel::Phone, $workRequest->intake_channel);
        $this->assertSame('고객사 담당자 010-0000-0000 통화', $workRequest->source_reference);
        $this->assertSame('주문 엑셀 다운로드 기능을 요청함', $workRequest->intake_summary);
    }

    public function test_manual_intake_without_source_and_summary_is_rejected_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        WorkRequest::factory()->create([
            'intake_channel' => IntakeChannel::Email,
            'source_reference' => null,
            'intake_summary' => null,
        ]);
    }

    public function test_late_registration_without_a_reason_is_rejected_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        WorkRequest::factory()->create([
            'requested_at' => '2026-09-30 10:00:00',
            'registered_at' => '2026-10-02 10:00:00',
            'late_entry_reason' => null,
        ]);
    }

    public function test_late_registration_with_a_reason_is_stored(): void
    {
        $workRequest = WorkRequest::factory()->create([
            'requested_at' => '2026-09-30 10:00:00',
            'registered_at' => '2026-10-02 10:00:00',
            'late_entry_reason' => '이메일 수신함 장애로 등록이 지연됨',
        ]);

        $this->assertSame('2026-09-30 10:00:00', $workRequest->requested_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 10:00:00', $workRequest->registered_at->format('Y-m-d H:i:s'));
        $this->assertSame('이메일 수신함 장애로 등록이 지연됨', $workRequest->late_entry_reason);
    }

    public function test_additional_request_is_linked_to_an_original_request_in_the_same_company(): void
    {
        $original = WorkRequest::factory()->create();
        $additional = WorkRequest::factory()
            ->for($original->company)
            ->for($original->project)
            ->create(['parent_request_id' => $original->id]);

        $this->assertTrue($additional->parentRequest->is($original));
        $this->assertCount(1, $original->additionalRequests);
        $this->assertTrue($original->additionalRequests->first()->is($additional));
    }

    public function test_additional_request_cannot_reference_another_company_request(): void
    {
        $original = WorkRequest::factory()->create();
        $anotherCompany = Company::factory()->create();
        $anotherProject = Project::factory()->for($anotherCompany)->create();

        $this->expectException(QueryException::class);

        WorkRequest::factory()
            ->for($anotherCompany)
            ->for($anotherProject)
            ->create(['parent_request_id' => $original->id]);
    }
}
