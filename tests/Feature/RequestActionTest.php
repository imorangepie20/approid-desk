<?php

namespace Tests\Feature;

use App\Actions\ApproveEstimateVersion;
use App\Actions\TransitionWorkRequest;
use App\Enums\UserRole;
use App\Enums\WorkRequestStatus as Status;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class RequestActionTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return iterable<string, array{UserRole, Status}> */
    public static function rolesAndStates(): iterable
    {
        foreach (UserRole::cases() as $role) {
            foreach (Status::cases() as $status) {
                yield $role->value.'-'.$status->value => [$role, $status];
            }
        }
    }

    #[DataProvider('rolesAndStates')]
    public function test_button_and_server_policy_matrix_without_approval(UserRole $role, Status $status): void
    {
        $request = WorkRequest::factory()->withSignedContract()->create(['status' => $status]);
        $factory = $role->isSystemRole() ? User::factory()->operator() : User::factory()->for($request->company);
        $user = $factory->create(['role' => $role]);
        $expected = $role->isSystemRole() ? match ($status) {
            Status::Received => [Status::Estimating, Status::OnHold, Status::Cancelled],
            Status::Estimating, Status::Queued => [Status::OnHold, Status::Cancelled],
            Status::AwaitingApproval, Status::OnHold => [Status::Cancelled],
            Status::InProgress => [Status::AwaitingReview, Status::OnHold, Status::Cancelled],
            Status::AwaitingReview => [Status::OnHold],
            default => [],
        } : [];
        $response = $this->actingAs($user)->get(route('requests.show', $request))->assertOk();
        foreach (Status::cases() as $to) {
            $allowed = in_array($to, $expected, true);
            $this->assertSame($allowed, Gate::forUser($user)->allows('transition', [$request, $to]));
            $marker = 'data-test="transition-'.$to->value.'"';
            $allowed ? $response->assertSee($marker, false) : $response->assertDontSee($marker, false);
            if (! $allowed) {
                $this->post(route('requests.transition', $request), $this->payload($request, $to))->assertForbidden();
            }
        }
        $canWrite = $role->isSystemRole() && in_array($status, [Status::Received, Status::Estimating], true);
        $canWrite ? $response->assertSee('data-test="write-estimate"', false) : $response->assertDontSee('data-test="write-estimate"', false);
    }

    public function test_hold_resume_replay_and_stale_state_protection(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $url = route('requests.transition', $request);
        $payload = $this->payload($request, Status::OnHold);
        $this->actingAs($operator)->post($url, $payload)->assertRedirect(route('requests.show', $request));
        $this->assertSame(Status::OnHold, $request->fresh()->status);
        $this->assertSame('상태 변경 사유', $request->statusChanges()->sole()->reason);
        $this->post($url, $payload)->assertSessionHasErrors('status');
        $this->assertSame(1, $request->statusChanges()->count());
        $this->post($url, $this->payload($request->fresh(), Status::Received))->assertRedirect();
        $this->assertSame(Status::Received, $request->fresh()->status);
        // The status is back at the original value, but the history token is stale.
        $this->post($url, $payload)->assertSessionHasErrors('status');
        $this->assertSame(2, $request->statusChanges()->count());
    }

    public function test_customer_scope_role_confirmation_and_required_reason_are_enforced(): void
    {
        $request = WorkRequest::factory()->create();
        $url = route('requests.transition', $request);
        $payload = $this->payload($request, Status::Cancelled);
        $this->post($url, $payload)->assertRedirect(route('login'));
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        $this->actingAs($admin)->post($url, $payload)->assertForbidden();
        $other = User::factory()->customerAdmin()->create();
        $this->actingAs($other)->post($url, $payload)->assertForbidden();
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->post($url, array_replace($payload, ['confirmed' => 0]))->assertSessionHasErrors('confirmed');
        $this->post($url, array_replace($payload, ['reason' => ' ']))->assertSessionHasErrors('status');
        $this->post($url, array_replace($payload, ['reason' => str_repeat('x', 10001)]))->assertSessionHasErrors('reason');
        $this->assertSame(Status::Received, $request->fresh()->status);
        $this->assertSame(0, $request->statusChanges()->count());
        $this->post($url, array_replace($payload, ['reason' => '<script>alert(1)</script>']))->assertRedirect();
        $this->get(route('requests.show', $request))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_approved_work_start_review_and_customer_revision_use_the_same_gate(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Test');
        $request->refresh();
        $url = route('requests.transition', $request);
        $this->actingAs($operator)->get(route('requests.show', $request))->assertSee('data-test="transition-in_progress"', false)->assertSee('data-test="transition-cancelled"', false);
        $this->post($url, $this->payload($request, Status::InProgress))->assertRedirect();
        $this->post($url, $this->payload($request->fresh(), Status::AwaitingReview))->assertRedirect();
        $this->post($url, $this->payload($request->fresh(), Status::InProgress))->assertForbidden();
        $this->actingAs($admin)->get(route('requests.show', $request))->assertSee('data-test="transition-in_progress"', false)->assertSee('data-test="transition-completed"', false);
        $this->post($url, $this->payload($request->fresh(), Status::InProgress))->assertRedirect();
        $this->assertSame(Status::InProgress, $request->fresh()->status);
        $this->assertSame(2, $month->entries()->count()); // Grant + reserve; no fake settlement.
    }

    public function test_approved_request_can_be_cancelled_through_the_real_action_and_releases_reservation(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Test');
        $request->refresh();
        $payload = $this->payload($request, Status::Cancelled);
        $this->actingAs($operator)->post(route('requests.transition', $request), $payload)
            ->assertRedirect(route('requests.show', $request));
        $this->assertSame(Status::Cancelled, $request->fresh()->status);
        $this->assertSame(60, (int) $month->entries()->where('type', 'release')->sum('minutes'));
        $this->assertSame(1, TimeLedgerEntry::where('source_type', 'work_request_status_change')->count());
        $this->post(route('requests.transition', $request), $payload)->assertSessionHasErrors('status');
        $this->assertSame(1, TimeLedgerEntry::where('source_type', 'work_request_status_change')->count());
    }

    public function test_customer_admin_can_complete_review_through_the_real_action(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Test');
        $request->refresh();
        $this->actingAs($operator)->post(route('requests.transition', $request),
            $this->payload($request, Status::InProgress))->assertRedirect();
        $request->refresh();
        $this->post(route('requests.transition', $request),
            $this->payload($request, Status::AwaitingReview))->assertRedirect();
        $request->refresh();
        $this->actingAs($admin)->post(route('requests.transition', $request),
            $this->payload($request, Status::Completed))->assertRedirect(route('requests.show', $request));
        $this->assertSame(Status::Completed, $request->fresh()->status);
        $this->assertSame(60, (int) $month->entries()->where('type', 'release')->sum('minutes'));
    }

    public function test_operator_can_explicitly_resume_completed_request_as_free_rework(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Free rework action test');
        $workflow = new TransitionWorkRequest;
        $workflow->handle($operator, $request, Status::InProgress);
        $workflow->handle($operator, $request, Status::AwaitingReview, '검수 요청');
        $workflow->handle($admin, $request, Status::Completed);
        $request->refresh();
        $ledgerCount = TimeLedgerEntry::count();

        $this->actingAs($admin)->get(route('requests.show', $request))
            ->assertDontSee('data-test="transition-in_progress"', false);
        $response = $this->actingAs($operator)->get(route('requests.show', $request))->assertOk();
        $response->assertSee('data-test="transition-in_progress"', false)
            ->assertSee('무상 재작업 시작')->assertSee('name="is_free_rework" value="1"', false);
        $this->post(route('requests.transition', $request), $this->payload($request, Status::InProgress))
            ->assertSessionHasErrors('is_free_rework');
        $this->assertSame(Status::Completed, $request->fresh()->status);

        $this->post(route('requests.transition', $request), array_replace(
            $this->payload($request, Status::InProgress),
            ['is_free_rework' => 1, 'reason' => '최초 합의 기능의 구현 오류'],
        ))->assertRedirect(route('requests.show', $request));
        $change = $request->statusChanges()->latest('id')->firstOrFail();
        $this->assertSame(Status::InProgress, $request->fresh()->status);
        $this->assertTrue($change->is_free_rework);
        $this->assertSame('최초 합의 기능의 구현 오류', $change->reason);
        $this->assertSame($ledgerCount, TimeLedgerEntry::count());
        $this->assertSame(60, (int) $month->entries()->where('type', 'release')->sum('minutes'));
    }

    public function test_free_rework_flag_cannot_be_added_to_an_ordinary_transition(): void
    {
        $request = WorkRequest::factory()->create();
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->post(route('requests.transition', $request), array_replace(
            $this->payload($request, Status::Estimating), ['is_free_rework' => 1],
        ))->assertSessionHasErrors('is_free_rework');
        $this->assertSame(Status::Received, $request->fresh()->status);
        $this->assertSame(0, $request->statusChanges()->count());
    }

    public function test_contract_expiry_blocks_button_and_direct_start(): void
    {
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Test');
        $this->travel(2)->years();
        $request->refresh();
        $this->actingAs($operator)->get(route('requests.show', $request))->assertDontSee('data-test="transition-in_progress"', false);
        $this->post(route('requests.transition', $request), $this->payload($request, Status::InProgress))->assertForbidden();
        $this->assertSame(Status::Queued, $request->fresh()->status);
    }

    public function test_estimate_decision_link_disappears_after_revision_and_generic_route_cannot_approve(): void
    {
        [$request, $operator, $admin, , $estimate] = $this->timeFixture();
        $this->actingAs($admin)->get(route('requests.show', $request))->assertSee('data-test="decide-estimate"', false);
        $this->post(route('requests.transition', $request), $this->payload($request, Status::Queued))->assertForbidden();
        $this->post(route('requests.estimates.revision', [$request, $estimate]), ['reason' => '범위 변경'])->assertRedirect();
        $this->get(route('requests.show', $request))->assertDontSee('data-test="decide-estimate"', false);
        $this->post(route('requests.estimates.approve', [$request, $estimate]), ['confirmed' => 1, 'approval_text' => ApproveEstimateVersion::APPROVAL_TEXT,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->actingAs($operator)->get(route('requests.show', $request))->assertSee('data-test="write-estimate"', false);
    }

    public function test_failed_audit_rolls_back_state(): void
    {
        $request = WorkRequest::factory()->create();
        $operator = User::factory()->operator()->create();
        WorkRequestStatusChange::creating(function (): void {
            throw new RuntimeException('audit unavailable');
        });
        $this->actingAs($operator)->post(route('requests.transition', $request), $this->payload($request, Status::OnHold))->assertStatus(500);
        $this->assertSame(Status::Received, $request->fresh()->status);
        $this->assertSame(0, $request->statusChanges()->count());
    }

    /** @return array<string, mixed> */
    private function payload(WorkRequest $request, Status $to): array
    {
        return ['status' => $to->value, 'expected_status' => $request->status->value,
            'expected_change' => (int) $request->statusChanges()->max('id'), 'confirmed' => 1, 'reason' => '상태 변경 사유'];
    }
}
