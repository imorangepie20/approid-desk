<?php

namespace Tests\Feature;

use App\Actions\ApproveEstimateVersion;
use App\Actions\CreateCustomerADemoRequest;
use App\Actions\SubmitCustomerADemoEstimate;
use App\Enums\Permission;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\NotificationDelivery;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerADemoUserApprovalBlockCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_the_read_only_customer_user_approval_boundary(): void
    {
        [$request, $estimate] = $this->demoAwaitingApproval();
        $statusChangeCount = $request->statusChanges()->count();
        $activityCount = $request->activities()->count();

        $this->artisan('desk:verify-customer-a-demo-user-approval-block')
            ->expectsOutput('Customer A general user approval is blocked.')
            ->assertSuccessful();

        $this->assertSame(WorkRequestStatus::AwaitingApproval, $request->fresh()->status);
        $this->assertNull($request->fresh()->approved_estimate_version_id);
        $this->assertSame($statusChangeCount, $request->statusChanges()->count());
        $this->assertSame($activityCount, $request->activities()->count());
        $this->assertSame(0, EstimateApproval::query()->count());
        $this->assertSame(0, TimeLedgerEntry::query()->where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(0, NotificationDelivery::query()->count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame($estimate->id, EstimateVersion::query()->sole()->id);
    }

    public function test_customer_user_can_read_the_demo_estimate_but_ui_routes_and_action_deny_decisions(): void
    {
        [$request, $estimate] = $this->demoAwaitingApproval();
        $customerUser = User::factory()->customerUser()->for($request->company)->create();
        $approvalPayload = [
            'confirmed' => 1,
            'approval_text' => ApproveEstimateVersion::APPROVAL_TEXT,
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->assertTrue(Gate::forUser($customerUser)->allows('view', $estimate));
        $this->assertFalse(Gate::forUser($customerUser)->allows(Permission::ApproveEstimates->value));
        $this->assertFalse(Gate::forUser($customerUser)->allows('approve', $estimate));
        $this->actingAs($customerUser)->get(route('requests.show', $request))
            ->assertOk()
            ->assertSee('180분')
            ->assertSee('180,000원')
            ->assertDontSee('견적 확인·승인 또는 수정 요청 →');
        $this->get(route('requests.estimates.decision', [$request, $estimate]))->assertForbidden();
        $this->post(route('requests.estimates.approve', [$request, $estimate]), $approvalPayload)->assertForbidden();
        $this->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => '일반 사용자 시도'])->assertForbidden();

        try {
            (new ApproveEstimateVersion)->handle(
                $customerUser,
                $estimate,
                (string) Str::uuid(),
                ApproveEstimateVersion::APPROVAL_TEXT,
                '203.0.113.29',
                'Customer A demo boundary test',
            );
            $this->fail('Expected the domain approval action to reject a customer user.');
        } catch (AuthorizationException) {
            $this->assertSame(WorkRequestStatus::AwaitingApproval, $request->fresh()->status);
        }

        $this->assertSame(0, EstimateApproval::query()->count());
        $this->assertSame(0, TimeLedgerEntry::query()->where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(2, $request->statusChanges()->count());
    }

    public function test_it_requires_the_4_28_submitted_estimate(): void
    {
        $this->artisan('desk:verify-customer-a-demo-user-approval-block')->assertFailed();

        $actor = User::factory()->superAdmin()->create();
        (new CreateCustomerADemoRequest)->handle($actor);

        $this->artisan('desk:verify-customer-a-demo-user-approval-block')->assertFailed();
        $this->assertSame(0, EstimateApproval::query()->count());
    }

    /** @return array{WorkRequest, EstimateVersion} */
    private function demoAwaitingApproval(): array
    {
        $actor = User::factory()->superAdmin()->create();
        $request = (new CreateCustomerADemoRequest)->handle($actor);
        $estimate = (new SubmitCustomerADemoEstimate)->handle($actor);

        return [$request->fresh(), $estimate];
    }
}
