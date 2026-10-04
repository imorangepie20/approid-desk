<?php

namespace Tests\Feature;

use App\Enums\MajorIncidentEventType;
use App\Models\Company;
use App\Models\MajorIncidentEvent;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MajorIncidentEventManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_records_history_from_detail_and_customer_can_read_it_without_the_form(): void
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

        $this->actingAs($operator)->get(route('requests.show', $incident))
            ->assertOk()
            ->assertSee('data-test="major-incident-history"', false)
            ->assertSee('data-test="major-incident-event-form"', false);

        $response = $this->post(route('requests.incident-events.store', $incident), [
            'event_type' => MajorIncidentEventType::FirstResponse->value,
            'occurred_at' => '2026-10-04T09:40',
            'summary' => '최초 상황 안내',
            'details' => '장애 영향 범위와 다음 안내 시각을 고객에게 전달했습니다.',
        ]);

        $response->assertRedirect(route('requests.show', $incident).'#major-incident-history');
        $this->actingAs($customer)->get(route('requests.show', $incident))
            ->assertOk()
            ->assertSee('최초 상황 안내')
            ->assertSee('장애 영향 범위와 다음 안내 시각을 고객에게 전달했습니다.')
            ->assertSee('내부 목표 이내 응답')
            ->assertDontSee('data-test="major-incident-event-form"', false);
    }

    public function test_customer_cannot_post_history_and_other_company_cannot_view_it(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 10:30:00');
        $company = Company::factory()->create();
        $customer = User::factory()->customerAdmin()->for($company)->create();
        $otherCustomer = User::factory()->customerUser()->create();
        $incident = WorkRequest::factory()->urgent()->for($company)->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);
        $payload = [
            'event_type' => MajorIncidentEventType::ResponseUpdate->value,
            'occurred_at' => '2026-10-04T10:00',
            'summary' => '진행 안내',
            'details' => '대응 진행 상황을 기록합니다.',
        ];

        $this->actingAs($customer)->post(route('requests.incident-events.store', $incident), $payload)->assertForbidden();
        $this->actingAs($otherCustomer)->get(route('requests.show', $incident))->assertForbidden();
        $this->assertSame(0, MajorIncidentEvent::query()->count());
    }

    public function test_http_validation_rejects_invalid_type_future_time_and_blank_content(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-04 10:30:00');
        $operator = User::factory()->operator()->create();
        $incident = WorkRequest::factory()->urgent()->create([
            'requested_at' => '2026-10-04 09:00:00',
            'registered_at' => '2026-10-04 09:00:00',
        ]);

        $this->actingAs($operator)
            ->from(route('requests.show', $incident))
            ->post(route('requests.incident-events.store', $incident), [
                'event_type' => 'invalid',
                'occurred_at' => '2026-10-04T10:31',
                'summary' => ' ',
                'details' => '',
            ])
            ->assertRedirect(route('requests.show', $incident))
            ->assertSessionHasErrors(['event_type', 'summary', 'details']);

        $this->assertSame(0, MajorIncidentEvent::query()->count());
    }
}
