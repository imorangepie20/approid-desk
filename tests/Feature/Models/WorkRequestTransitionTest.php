<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\AssessRequestPricing;
use App\Actions\CreateEstimateVersion;
use App\Actions\ProvideContractMonth;
use App\Actions\SubmitEstimateVersion;
use App\Actions\TransitionWorkRequest;
use App\Enums\UserRole;
use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestActivityType;
use App\Enums\WorkRequestStatus as Status;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestActivity;
use App\Models\WorkRequestStatusChange;
use App\Services\WorkRequestTransitionRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkRequestTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_matrix_matches_documented_destinations(): void
    {
        $expected = [
            'received' => ['estimating', 'on_hold', 'cancelled'],
            'estimating' => ['awaiting_approval', 'on_hold', 'cancelled'],
            'awaiting_approval' => ['queued', 'estimating', 'cancelled'],
            'queued' => ['in_progress', 'awaiting_approval', 'on_hold', 'cancelled'],
            'in_progress' => ['awaiting_review', 'awaiting_approval', 'queued', 'on_hold', 'cancelled'],
            'awaiting_review' => ['completed', 'in_progress', 'on_hold'],
            'completed' => ['in_progress'],
            'on_hold' => ['cancelled'],
            'cancelled' => [],
        ];
        $rules = new WorkRequestTransitionRules;
        foreach (Status::cases() as $from) {
            $this->assertSame($expected[$from->value], array_map(fn (Status $s) => $s->value, $rules->destinations($from)));
        }
    }

    public function test_transition_records_actor_reason_and_one_activity(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $change = (new TransitionWorkRequest)->handle($operator, $request, Status::Estimating, '요청 분석 시작');
        $this->assertSame(Status::Estimating, $request->fresh()->status);
        $this->assertSame(Status::Received, $change->from_status);
        $this->assertSame(Status::Estimating, $change->to_status);
        $this->assertSame($operator->id, $change->changed_by);
        $this->assertSame('요청 분석 시작', $change->reason);
        $this->assertNotNull($change->occurred_at);
        $this->assertSame(1, $request->statusChanges()->count());
        $activity = $request->activities()->where('type', WorkRequestActivityType::StatusChanged->value)->sole();
        $this->assertSame($operator->id, $activity->actor_id);
        $this->assertSame($change->id, $activity->after_values['status_change_id']);
    }

    /** @return array<string, array{Status, Status, bool}> */
    public static function reasonTransitions(): array
    {
        return [
            'hold' => [Status::Received, Status::OnHold, false],
            'cancel' => [Status::Received, Status::Cancelled, false],
            'estimate revision' => [Status::AwaitingApproval, Status::Estimating, true],
            'review revision' => [Status::AwaitingReview, Status::InProgress, true],
            'additional approval' => [Status::InProgress, Status::Queued, false],
        ];
    }

    #[DataProvider('reasonTransitions')]
    public function test_reasons_are_required(Status $from, Status $to, bool $customer): void
    {
        $request = WorkRequest::factory()->withSignedContract()->create(['status' => $from]);
        $actor = $customer ? User::factory()->customerAdmin()->for($request->company)->create() : User::factory()->operator()->create();
        $this->expectException(ValidationException::class);
        (new TransitionWorkRequest)->handle($actor, $request, $to, ' ');
    }

    public function test_hold_can_resume_only_its_previous_state_with_reason(): void
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->create();
        $action = new TransitionWorkRequest;
        $action->handle($operator, $request, Status::OnHold, '자료 대기');
        try {
            $action->handle($operator, $request, Status::Estimating, '임의 재개');
            $this->fail('Wrong resume state accepted.');
        } catch (ValidationException) {
            $this->assertSame(Status::OnHold, $request->fresh()->status);
        }
        $action->handle($operator, $request, Status::Received, '자료 도착');
        $this->assertSame(Status::Received, $request->fresh()->status);
        $this->assertSame(2, $request->statusChanges()->count());
    }

    public function test_cancelled_request_cannot_be_resumed(): void
    {
        $request = WorkRequest::factory()->create(['status' => Status::Cancelled]);
        $this->expectException(ValidationException::class);
        (new TransitionWorkRequest)->handle(User::factory()->operator()->create(), $request, Status::Estimating, '재개');
    }

    public function test_general_customer_cannot_change_status(): void
    {
        $request = WorkRequest::factory()->create();
        $this->expectException(AuthorizationException::class);
        (new TransitionWorkRequest)->handle($request->submitter, $request, Status::Estimating);
    }

    public function test_customer_cannot_complete_another_company_request(): void
    {
        $request = WorkRequest::factory()->create(['status' => Status::AwaitingReview]);
        $this->expectException(AuthorizationException::class);
        (new TransitionWorkRequest)->handle(User::factory()->customerAdmin()->create(), $request, Status::Completed);
    }

    public function test_operator_cannot_replace_customer_review_confirmation(): void
    {
        $request = WorkRequest::factory()->create(['status' => Status::AwaitingReview]);
        $this->expectException(AuthorizationException::class);
        (new TransitionWorkRequest)->handle(User::factory()->operator()->create(), $request, Status::Completed);
    }

    public function test_missing_submission_blocks_approval_wait(): void
    {
        $request = WorkRequest::factory()->create(['status' => Status::Estimating]);
        $this->expectException(ValidationException::class);
        (new TransitionWorkRequest)->handle(User::factory()->operator()->create(), $request, Status::AwaitingApproval);
    }

    public function test_full_approved_flow_records_related_estimate_and_free_rework(): void
    {
        [$request, $operator, $admin] = $this->approvedRequest();
        $action = new TransitionWorkRequest;
        $queued = $request->statusChanges()->where('to_status', Status::Queued->value)->sole();
        $this->assertSame($request->fresh()->approvedEstimateVersion()->firstOrFail()->id, $queued->estimate_version_id);
        $action->handle($operator, $request, Status::InProgress);
        $action->handle($operator, $request, Status::AwaitingReview, '구현 및 내부 검사 완료');
        $action->handle($admin, $request, Status::Completed);
        $rework = $action->handle($operator, $request, Status::InProgress, '최초 합의 기능 오류 수정', true);
        $this->assertTrue($rework->is_free_rework);
        $this->assertSame(Status::InProgress, $request->fresh()->status);
    }

    public function test_unsigned_contract_blocks_work_and_rolls_back_history(): void
    {
        [$request, $operator, $admin] = $this->approvedRequest();
        $contract = $request->serviceContract()->firstOrFail();
        $contract->forceFill(['signature_confirmed_at' => null, 'signature_confirmed_by' => null])->save();
        $count = $request->statusChanges()->count();
        try {
            (new TransitionWorkRequest)->handle($operator, $request, Status::InProgress);
            $this->fail('Unsigned contract accepted.');
        } catch (ValidationException) {
            $this->assertSame(Status::Queued, $request->fresh()->status);
            $this->assertSame($count, $request->statusChanges()->count());
        }
    }

    public function test_activity_failure_rolls_back_both_status_and_history(): void
    {
        $request = WorkRequest::factory()->create();
        WorkRequestActivity::creating(function (): void {
            throw new RuntimeException('Simulated activity failure');
        });
        try {
            (new TransitionWorkRequest)->handle(User::factory()->operator()->create(), $request, Status::Estimating);
            $this->fail('Expected failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated activity failure', $e->getMessage());
            $this->assertSame(Status::Received, $request->fresh()->status);
            $this->assertSame(0, $request->statusChanges()->count());
        }
    }

    public function test_direct_model_status_update_is_rejected(): void
    {
        $request = WorkRequest::factory()->create();
        $this->expectException(ValidationException::class);
        $request->update(['status' => Status::Estimating]);
    }

    public function test_history_policy_and_scope_block_other_company(): void
    {
        $request = WorkRequest::factory()->create();
        $change = (new TransitionWorkRequest)->handle(User::factory()->operator()->create(), $request, Status::Estimating);
        $other = User::factory()->create();
        $this->assertTrue(Gate::forUser($request->submitter)->allows('view', $change));
        $this->assertFalse(Gate::forUser($other)->allows('view', $change));
        $this->assertSame(0, WorkRequestStatusChange::visibleTo($other)->count());
    }

    public function test_history_cannot_be_updated_by_bulk_query(): void
    {
        $change = (new TransitionWorkRequest)->handle(User::factory()->operator()->create(), WorkRequest::factory()->create(), Status::Estimating);
        $this->expectException(QueryException::class);
        WorkRequestStatusChange::whereKey($change->id)->update(['reason' => '변조']);
    }

    public function test_history_cannot_be_deleted_by_bulk_query(): void
    {
        $change = (new TransitionWorkRequest)->handle(User::factory()->operator()->create(), WorkRequest::factory()->create(), Status::Estimating);
        $this->expectException(QueryException::class);
        WorkRequestStatusChange::whereKey($change->id)->delete();
    }

    /** @return array{WorkRequest, User, User} */
    private function approvedRequest(): array
    {
        $request = WorkRequest::factory()->withSignedContract()->create();
        $operator = User::factory()->operator()->create();
        $admin = User::factory()->create(['company_id' => $request->company_id, 'role' => UserRole::CustomerAdmin]);
        PricingRule::factory()->create();
        (new ProvideContractMonth)->handle($operator, $request->serviceContract, today()->startOfMonth()->toDateString(), 1200);
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, 60, today(), '견적 판단');
        $estimate = (new CreateEstimateVersion)->handle($operator, $request, $assessment, '포함', '제외', today()->addWeek(), today()->startOfMonth());
        (new SubmitEstimateVersion)->handle($operator, $estimate);
        (new TransitionWorkRequest)->handle($operator, $request, Status::Estimating);
        (new TransitionWorkRequest)->handle($operator, $request, Status::AwaitingApproval);
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '203.0.113.1', 'Test');

        return [$request, $operator, $admin];
    }
}
