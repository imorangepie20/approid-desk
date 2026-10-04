<?php

namespace Tests\Feature;

use App\Actions\StartMajorIncidentRollback;
use App\Enums\MajorIncidentRollbackOutcome;
use App\Models\Company;
use App\Models\MajorIncidentRollback;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MajorIncidentRollbackManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_records_rollback_and_customer_reads_it_without_action_forms(): void
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

        $this->actingAs($operator)->get(route('requests.show', $incident))
            ->assertOk()
            ->assertSee('data-test="major-incident-rollback-start-form"', false);
        $response = $this->post(route('requests.rollbacks.store', $incident), [
            'rollback_target' => 'API release-42',
            'rollback_plan' => '이전 이미지로 전환합니다.',
            'rollback_verification_plan' => '오류율과 핵심 경로를 확인합니다.',
            'rollback_started_at' => '2026-10-04T10:00',
            'rollback_start_confirmed' => '1',
        ]);
        $rollback = MajorIncidentRollback::query()->sole();

        $response->assertRedirect(route('requests.show', $incident).'#major-incident-rollbacks');
        $this->actingAs($customer)->get(route('requests.show', $incident))
            ->assertOk()
            ->assertSee('API release-42')
            ->assertSee('이전 이미지로 전환합니다.')
            ->assertDontSee('data-test="major-incident-rollback-start-form"', false)
            ->assertDontSee('data-test="major-incident-rollback-complete-form"', false);

        $this->actingAs($operator)->post(route('requests.rollbacks.complete', [$incident, $rollback]), [
            'rollback_outcome' => MajorIncidentRollbackOutcome::Succeeded->value,
            'rollback_result_summary' => '복구 확인',
            'rollback_result_details' => '오류율과 핵심 경로가 정상입니다.',
            'rollback_completed_at' => '2026-10-04T10:30',
            'rollback_result_confirmed' => '1',
        ])->assertRedirect(route('requests.show', $incident).'#major-incident-rollbacks');

        $this->actingAs($customer)->get(route('requests.show', $incident))
            ->assertOk()
            ->assertSee('복구 확인')
            ->assertSee('성공');
    }

    public function test_customer_cannot_start_or_complete_and_nested_request_mismatch_is_not_found(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $customer = User::factory()->customerAdmin()->for($company)->create();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->for($company)->create();
        $otherIncident = WorkRequest::factory()->urgent()->create();
        $payload = [
            'rollback_target' => '대상',
            'rollback_plan' => '계획',
            'rollback_verification_plan' => '검증',
            'rollback_started_at' => now()->format('Y-m-d\TH:i'),
            'rollback_start_confirmed' => '1',
        ];

        $this->actingAs($customer)->post(route('requests.rollbacks.store', $incident), $payload)->assertForbidden();
        $rollback = (new StartMajorIncidentRollback)->handle($operator, $incident, '대상', '계획', '검증', now(), true);
        $result = [
            'rollback_outcome' => 'failed',
            'rollback_result_summary' => '결과',
            'rollback_result_details' => '상세',
            'rollback_completed_at' => now()->format('Y-m-d\TH:i'),
            'rollback_result_confirmed' => '1',
        ];

        $this->actingAs($customer)->post(route('requests.rollbacks.complete', [$incident, $rollback]), $result)->assertForbidden();
        $this->actingAs($operator)->post(route('requests.rollbacks.complete', [$otherIncident, $rollback]), $result)->assertNotFound();
        $this->assertTrue($rollback->fresh()->isActive());
    }

    public function test_http_requires_explicit_confirmations_and_valid_outcome(): void
    {
        Notification::fake();
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create();

        $this->actingAs($operator)->post(route('requests.rollbacks.store', $incident), [
            'rollback_target' => '대상',
            'rollback_plan' => '계획',
            'rollback_verification_plan' => '검증',
            'rollback_started_at' => now()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('rollback_start_confirmed');

        $rollback = (new StartMajorIncidentRollback)->handle($operator, $incident, '대상', '계획', '검증', now(), true);
        $this->actingAs($operator)->post(route('requests.rollbacks.complete', [$incident, $rollback]), [
            'rollback_outcome' => 'unknown',
            'rollback_result_summary' => '결과',
            'rollback_result_details' => '상세',
            'rollback_completed_at' => now()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors(['rollback_outcome', 'rollback_result_confirmed']);
    }
}
