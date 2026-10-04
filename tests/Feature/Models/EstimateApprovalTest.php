<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\AssessRequestPricing;
use App\Actions\CreateEstimateVersion;
use App\Actions\ProvideContractMonth;
use App\Actions\SubmitEstimateVersion;
use App\Actions\TransitionWorkRequest;
use App\Enums\CompanyStatus;
use App\Enums\TimeLedgerType;
use App\Enums\UserRole;
use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestActivityType;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestActivity;
use App\Models\WorkRequestStatusChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EstimateApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $admin;

    private WorkRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->operator()->create();
        $this->request = WorkRequest::factory()->withSignedContract()->create(['status' => WorkRequestStatus::AwaitingApproval]);
        $this->admin = User::factory()->create(['company_id' => $this->request->company_id, 'role' => UserRole::CustomerAdmin]);
        PricingRule::factory()->create();
        (new ProvideContractMonth)->handle($this->operator, $this->request->serviceContract, today()->startOfMonth()->toDateString(), 1200);
    }

    public function test_approval_records_evidence_and_encrypts_connection_information(): void
    {
        $estimate = $this->estimate();
        $approval = $this->approve($estimate);
        $this->assertSame($this->admin->id, $approval->approved_by);
        $this->assertSame(UserRole::CustomerAdmin, $approval->approver_role);
        $this->assertSame(ApproveEstimateVersion::APPROVAL_TEXT, $approval->approval_text);
        $this->assertSame($estimate->id, $approval->estimateVersion()->firstOrFail()->id);
        $this->assertSame($approval->id, $estimate->approval()->firstOrFail()->id);
        $this->assertSame($estimate->id, $this->request->fresh()->approvedEstimateVersion()->firstOrFail()->id);
        $this->assertSame(WorkRequestStatus::Queued, $this->request->fresh()->status);
        $change = $this->request->statusChanges()->sole();
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $change->from_status);
        $this->assertSame(WorkRequestStatus::Queued, $change->to_status);
        $this->assertSame($estimate->id, $change->estimate_version_id);
        $this->assertNotNull($approval->approved_at);
        $this->assertSame('203.0.113.10', $approval->fresh()->ip_address);
        $this->assertSame('Test browser', $approval->fresh()->user_agent);
        $raw = DB::table('estimate_approvals')->find($approval->id);
        $this->assertNotSame('203.0.113.10', $raw->ip_address);
        $this->assertNotSame('Test browser', $raw->user_agent);
        $this->assertArrayNotHasKey('ip_address', $approval->toArray());
        $this->assertArrayNotHasKey('user_agent', $approval->toArray());
        $this->assertStringNotContainsString('203.0.113.10', $this->request->activities()->get()->toJson());
    }

    public function test_same_key_retries_return_original_evidence_without_duplicate_activity(): void
    {
        $estimate = $this->estimate();
        $key = (string) Str::uuid();
        $first = $this->approve($estimate, $key);
        $activityCount = $this->request->activities()->count();
        $this->travel(1)->hours();
        $retry = $this->approve($estimate, strtoupper($key));
        $this->assertSame($first->id, $retry->id);
        $this->assertTrue($first->approved_at->equalTo($retry->approved_at));
        $this->assertSame(1, EstimateApproval::count());
        $this->assertSame(1, $this->request->statusChanges()->count());
        $this->assertSame($activityCount, $this->request->activities()->count());
        $this->assertSame(1, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
    }

    public function test_old_retry_remains_valid_after_a_newer_version_replaces_the_reservation(): void
    {
        $first = $this->estimate();
        $key = (string) Str::uuid();
        $oldApproval = $this->approve($first, $key);
        // Set up a subsequent approval cycle; the complete HTTP workflow is covered separately.
        WorkRequest::whereKey($this->request->id)->update(['status' => WorkRequestStatus::AwaitingApproval->value]);
        $second = $this->estimate();
        $newApproval = $this->approve($second);
        $this->assertSame($oldApproval->id, $this->approve($first, $key)->id);
        $this->assertNotSame($oldApproval->id, $newApproval->id);
        $this->assertSame($second->id, $this->request->fresh()->approvedEstimateVersion()->firstOrFail()->id);
        $this->assertSame(2, EstimateApproval::count());
        $this->assertSame(2, $this->request->statusChanges()->count());
        $this->assertSame(2, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(1, TimeLedgerEntry::where('source_type', 'estimate_replacement')->count());
    }

    public function test_different_key_cannot_approve_same_version_twice(): void
    {
        $estimate = $this->estimate();
        $this->approve($estimate);
        $this->expectException(ValidationException::class);
        $this->approve($estimate);
    }

    public function test_same_key_cannot_be_reused_for_another_version(): void
    {
        $key = (string) Str::uuid();
        $this->approve($this->estimate(), $key);
        $this->expectException(ValidationException::class);
        $this->approve($this->estimate(), $key);
    }

    public function test_another_admin_cannot_reuse_original_approvers_key(): void
    {
        $estimate = $this->estimate();
        $key = (string) Str::uuid();
        $this->approve($estimate, $key);
        $this->admin = User::factory()->create(['company_id' => $this->request->company_id, 'role' => UserRole::CustomerAdmin]);
        $this->expectException(ValidationException::class);
        $this->approve($estimate, $key);
    }

    /** @return array<string, array{UserRole}> */
    public static function forbiddenRoles(): array
    {
        return ['customer user' => [UserRole::CustomerUser], 'operator' => [UserRole::Operator], 'super admin' => [UserRole::SuperAdmin]];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_only_customer_admin_can_approve(UserRole $role): void
    {
        $estimate = $this->estimate();
        $this->admin = User::factory()->create(['role' => $role, 'company_id' => $role->isSystemRole() ? null : $this->request->company_id]);
        $this->expectException(AuthorizationException::class);
        $this->approve($estimate);
    }

    public function test_other_company_admin_is_forbidden(): void
    {
        $estimate = $this->estimate();
        $this->admin = User::factory()->create(['role' => UserRole::CustomerAdmin]);
        $this->expectException(AuthorizationException::class);
        $this->approve($estimate);
    }

    public function test_inactive_company_blocks_approval(): void
    {
        $estimate = $this->estimate();
        $this->request->company->update(['status' => CompanyStatus::Inactive]);
        $this->expectException(AuthorizationException::class);
        $this->approve($estimate);
    }

    public function test_role_is_rechecked_using_fresh_database_state(): void
    {
        $estimate = $this->estimate();
        User::whereKey($this->admin->id)->update(['role' => UserRole::CustomerUser->value]);
        $this->expectException(AuthorizationException::class);
        $this->approve($estimate);
    }

    public function test_unsubmitted_latest_version_is_not_approvable(): void
    {
        $estimate = $this->estimate(false);
        $this->expectException(ValidationException::class);
        $this->approve($estimate);
    }

    public function test_older_submission_is_not_approvable_when_new_draft_exists(): void
    {
        $old = $this->estimate();
        $this->estimate(false);
        $this->expectException(ValidationException::class);
        $this->approve($old);
    }

    public function test_cancelled_request_is_not_approvable(): void
    {
        $estimate = $this->estimate();
        (new TransitionWorkRequest)->handle($this->operator, $this->request, WorkRequestStatus::Cancelled, '고객 취소');
        $this->expectException(ValidationException::class);
        $this->approve($estimate);
    }

    public function test_wrong_consent_text_is_rejected(): void
    {
        $estimate = $this->estimate();
        $this->expectException(ValidationException::class);
        (new ApproveEstimateVersion)->handle($this->admin, $estimate, (string) Str::uuid(), '확인', '203.0.113.10', 'Test browser');
    }

    public function test_pointer_update_failure_rolls_back_approval(): void
    {
        $estimate = $this->estimate();
        WorkRequest::updating(function (WorkRequest $request): void {
            if ($request->isDirty('approved_estimate_version_id')) {
                throw new RuntimeException('Simulated save failure');
            }
        });
        try {
            $this->approve($estimate);
            $this->fail('Expected save failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated save failure', $e->getMessage());
            $this->assertSame(0, EstimateApproval::count());
            $this->assertNull($this->request->approvedEstimateVersion()->first());
        }
    }

    public function test_approval_is_visible_only_in_own_company(): void
    {
        $approval = $this->approve($this->estimate());
        $other = User::factory()->create();
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $approval));
        $this->assertFalse(Gate::forUser($other)->allows('view', $approval));
        $this->assertSame(0, EstimateApproval::visibleTo($other)->count());
    }

    public function test_database_blocks_evidence_update(): void
    {
        $approval = $this->approve($this->estimate());
        $this->expectException(QueryException::class);
        EstimateApproval::whereKey($approval->id)->update(['approval_text' => '변조']);
    }

    public function test_database_blocks_evidence_deletion(): void
    {
        $approval = $this->approve($this->estimate());
        $this->expectException(QueryException::class);
        EstimateApproval::whereKey($approval->id)->delete();
    }

    public function test_unsigned_contract_rolls_back_approval_pointer_and_all_new_history(): void
    {
        $estimate = $this->estimate();
        $this->request->serviceContract()->firstOrFail()->forceFill([
            'signature_confirmed_at' => null,
            'signature_confirmed_by' => null,
        ])->save();
        $activityCount = $this->request->activities()->count();
        try {
            $this->approve($estimate);
            $this->fail('Unsigned contract accepted.');
        } catch (ValidationException) {
            $this->assertUnchangedApprovalState($activityCount);
        }
    }

    /** @return array<string, array{class-string}> */
    public static function failingHistoryModels(): array
    {
        return ['status history' => [WorkRequestStatusChange::class], 'activity history' => [WorkRequestActivity::class]];
    }

    /** @param class-string $model */
    #[DataProvider('failingHistoryModels')]
    public function test_history_failure_rolls_back_the_entire_approval(string $model): void
    {
        $estimate = $this->estimate();
        $activityCount = $this->request->activities()->count();
        $model::creating(function ($record): void {
            if ($record instanceof WorkRequestActivity && $record->type !== WorkRequestActivityType::StatusChanged) {
                return;
            }
            throw new RuntimeException('Simulated history failure');
        });
        try {
            $this->approve($estimate);
            $this->fail('Expected history failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated history failure', $e->getMessage());
            $this->assertUnchangedApprovalState($activityCount);
        }
    }

    public function test_approval_outside_awaiting_approval_is_rejected(): void
    {
        $estimate = $this->estimate();
        (new TransitionWorkRequest)->handle($this->admin, $this->request, WorkRequestStatus::Estimating, '견적 수정 요청');
        $activityCount = $this->request->activities()->count();
        try {
            $this->approve($estimate);
            $this->fail('Approval outside approval-wait state accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, EstimateApproval::count());
            $this->assertSame(WorkRequestStatus::Estimating, $this->request->fresh()->status);
            $this->assertSame($activityCount, $this->request->activities()->count());
        }
    }

    private function assertUnchangedApprovalState(int $activityCount): void
    {
        $this->assertSame(0, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(0, EstimateApproval::count());
        $this->assertNull($this->request->fresh()->approvedEstimateVersion()->first());
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $this->request->fresh()->status);
        $this->assertSame(0, $this->request->statusChanges()->count());
        $this->assertSame($activityCount, $this->request->activities()->count());
    }

    private function estimate(bool $submit = true): EstimateVersion
    {
        $assessment = (new AssessRequestPricing)->handle($this->operator, $this->request, WorkDifficulty::Normal, 60, today(), '기능 범위 검토');
        $estimate = (new CreateEstimateVersion)->handle($this->operator, $this->request, $assessment, '개발 범위', '제외 범위', today()->addWeek(), today()->startOfMonth());

        return $submit ? (new SubmitEstimateVersion)->handle($this->operator, $estimate) : $estimate;
    }

    private function approve(EstimateVersion $estimate, ?string $key = null): EstimateApproval
    {
        return (new ApproveEstimateVersion)->handle($this->admin, $estimate, $key ?? (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '203.0.113.10', 'Test browser');
    }
}
