<?php

namespace Tests\Feature\Models;

use App\Actions\ApproveEstimateVersion;
use App\Actions\AssessRequestPricing;
use App\Actions\ConfirmWorkLog;
use App\Actions\CreateEstimateVersion;
use App\Actions\ProvideContractMonth;
use App\Actions\SaveWorkLogDraft;
use App\Actions\SubmitEstimateVersion;
use App\Actions\TransitionWorkRequest;
use App\Enums\TimeLedgerType;
use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class EstimateReservationReplacementTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $admin;

    private WorkRequest $request;

    private ContractMonth $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->operator()->create();
        $this->request = WorkRequest::factory()->withSignedContract()->create(['status' => WorkRequestStatus::AwaitingApproval]);
        $this->admin = User::factory()->customerAdmin()->for($this->request->company)->create();
        PricingRule::factory()->create();
        $this->month = (new ProvideContractMonth)->handle(
            $this->operator,
            $this->request->serviceContract,
            today()->startOfMonth()->toDateString(),
            200,
        );
    }

    public function test_same_month_reestimate_releases_old_reservation_and_reserves_new_version(): void
    {
        $first = $this->estimate(60);
        $this->approve($first);
        $second = $this->replacement(90);
        $key = (string) Str::uuid();
        $approval = $this->approve($second, $key);

        $release = TimeLedgerEntry::query()->where('source_type', 'estimate_replacement')->sole();
        $this->assertSame(TimeLedgerType::Release, $release->type);
        $this->assertSame(60, $release->minutes);
        $this->assertSame($approval->id, $release->source_id);
        $this->assertSame($this->admin->id, $release->actor_id);
        $this->assertSame('견적 변경 기존 예약 해제', $release->reason);
        $newReserve = TimeLedgerEntry::query()->where('source_type', 'estimate_approval')
            ->where('source_id', $approval->id)->sole();
        $this->assertSame(TimeLedgerType::Reserve, $newReserve->type);
        $this->assertSame(90, $newReserve->minutes);
        $this->assertSame($second->id, $this->request->fresh()->approved_estimate_version_id);
        $this->assertSame(WorkRequestStatus::Queued, $this->request->fresh()->status);
        $this->assertSame(110, $this->available($this->month));
        $this->assertSame(2, EstimateApproval::count());

        $retry = $this->approve($second, strtoupper($key));
        $this->assertSame($approval->id, $retry->id);
        $this->assertSame(1, TimeLedgerEntry::where('source_type', 'estimate_replacement')->count());
        $this->assertSame(2, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
    }

    public function test_only_remaining_reservation_is_released_after_partial_usage(): void
    {
        $first = $this->estimate(100);
        $this->approve($first);
        $log = (new SaveWorkLogDraft)->handle($this->operator, $this->request, [
            'worked_on' => today()->toDateString(),
            'description' => '재견적 전 작업',
            'minutes' => 40,
            'is_billable' => true,
        ]);
        (new ConfirmWorkLog)->handle($this->operator, $log, 1);

        $second = $this->replacement(80);
        $this->approve($second);

        $replacement = TimeLedgerEntry::query()->where('source_type', 'estimate_replacement')->sole();
        $this->assertSame(60, $replacement->minutes);
        $this->assertSame(40, (int) TimeLedgerEntry::where('type', TimeLedgerType::Usage)->sum('minutes'));
        $this->assertSame(100, (int) TimeLedgerEntry::where('type', TimeLedgerType::Release)->sum('minutes'));
        $this->assertSame(80, $this->available($this->month));
    }

    public function test_reestimate_can_move_the_remaining_reservation_to_another_month(): void
    {
        $nextMonth = (new ProvideContractMonth)->handle(
            $this->operator,
            $this->request->serviceContract,
            today()->startOfMonth()->addMonth()->toDateString(),
            150,
        );
        $this->approve($this->estimate(60));
        $second = $this->replacement(80, 1);
        $approval = $this->approve($second);

        $release = TimeLedgerEntry::query()->where('source_type', 'estimate_replacement')->sole();
        $reserve = TimeLedgerEntry::query()->where('source_type', 'estimate_approval')
            ->where('source_id', $approval->id)->sole();
        $this->assertSame($this->month->id, $release->contract_month_id);
        $this->assertSame(60, $release->minutes);
        $this->assertSame($nextMonth->id, $reserve->contract_month_id);
        $this->assertSame(80, $reserve->minutes);
        $this->assertSame(200, $this->available($this->month));
        $this->assertSame(70, $this->available($nextMonth));
    }

    public function test_insufficient_new_month_rolls_back_release_approval_pointer_and_state(): void
    {
        $nextMonth = (new ProvideContractMonth)->handle(
            $this->operator,
            $this->request->serviceContract,
            today()->startOfMonth()->addMonth()->toDateString(),
            50,
        );
        $first = $this->estimate(60);
        $oldApproval = $this->approve($first);
        $second = $this->replacement(80, 1);

        try {
            $this->approve($second);
            $this->fail('Insufficient replacement capacity accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('minutes', $error->errors());
        }

        $this->assertSame(0, TimeLedgerEntry::where('source_type', 'estimate_replacement')->count());
        $this->assertSame(1, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(1, EstimateApproval::count());
        $this->assertSame($oldApproval->id, EstimateApproval::sole()->id);
        $this->assertSame($first->id, $this->request->fresh()->approved_estimate_version_id);
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $this->request->fresh()->status);
        $this->assertSame(140, $this->available($this->month));
        $this->assertSame(50, $this->available($nextMonth));
    }

    public function test_failure_after_release_rolls_back_the_entire_replacement(): void
    {
        $first = $this->estimate(60);
        $this->approve($first);
        $second = $this->replacement(80);
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$armed): void {
            if ($armed && str_starts_with($sql, 'insert into `time_ledger_entries`')
                && in_array(TimeLedgerType::Reserve->value, $bindings, true)) {
                throw new RuntimeException('Injected replacement reserve failure');
            }
        });

        try {
            $this->approve($second);
            $this->fail('Expected replacement failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected replacement reserve failure', $error->getMessage());
        } finally {
            $armed = false;
        }

        $this->assertSame(0, TimeLedgerEntry::where('source_type', 'estimate_replacement')->count());
        $this->assertSame(1, EstimateApproval::count());
        $this->assertSame($first->id, $this->request->fresh()->approved_estimate_version_id);
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $this->request->fresh()->status);
        $this->assertSame(140, $this->available($this->month));
    }

    public function test_fully_consumed_old_reservation_needs_no_replacement_release(): void
    {
        $this->approve($this->estimate(60));
        $log = (new SaveWorkLogDraft)->handle($this->operator, $this->request, [
            'worked_on' => today()->toDateString(),
            'description' => '기존 예약 전액 사용',
            'minutes' => 60,
            'is_billable' => true,
        ]);
        (new ConfirmWorkLog)->handle($this->operator, $log, 1);

        $second = $this->replacement(30);
        $this->approve($second);

        $this->assertSame(0, TimeLedgerEntry::where('source_type', 'estimate_replacement')->count());
        $this->assertSame(2, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->assertSame(110, $this->available($this->month));
    }

    public function test_operator_and_customer_can_complete_the_reestimate_through_real_http_routes(): void
    {
        $first = $this->estimate(60);
        $this->approve($first);

        $this->actingAs($this->operator)->get(route('requests.show', $this->request))
            ->assertOk()->assertSee('data-test="write-estimate"', false);
        $this->post(route('requests.estimates.store', $this->request), [
            'base_version' => 1,
            'difficulty' => WorkDifficulty::Normal->value,
            'estimated_minutes' => 90,
            'rationale' => '추가 범위 반영',
            'included_scope' => '추가 개발 범위',
            'excluded_scope' => '별도 협의 범위',
            'scheduled_on' => today()->addWeek()->toDateString(),
            'usage_month' => today()->format('Y-m'),
        ])->assertRedirect();
        $second = $this->request->latestEstimateVersion()->firstOrFail();
        $this->assertSame(2, $second->version);
        $this->assertSame(WorkRequestStatus::Queued, $this->request->fresh()->status);

        $this->post(route('requests.estimates.submit', [$this->request, $second]), ['confirmed' => 1])
            ->assertRedirect(route('requests.show', $this->request));
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $this->request->fresh()->status);
        $this->assertSame($first->id, $this->request->fresh()->approved_estimate_version_id);

        $decision = $this->actingAs($this->admin)
            ->get(route('requests.estimates.decision', [$this->request, $second]))
            ->assertOk()->assertSee('기존 견적의 남은 예약을 해제');
        $this->withHeaders(['User-Agent' => 'Replacement browser test'])
            ->post(route('requests.estimates.approve', [$this->request, $second]), [
                'confirmed' => 1,
                'approval_text' => ApproveEstimateVersion::APPROVAL_TEXT,
                'idempotency_key' => $decision->viewData('idempotencyKey'),
            ])->assertRedirect(route('requests.show', $this->request));

        $this->assertSame($second->id, $this->request->fresh()->approved_estimate_version_id);
        $this->assertSame(WorkRequestStatus::Queued, $this->request->fresh()->status);
        $this->assertSame(60, TimeLedgerEntry::where('source_type', 'estimate_replacement')->sole()->minutes);
        $this->assertSame(90, TimeLedgerEntry::where('source_type', 'estimate_approval')
            ->where('source_id', EstimateApproval::latest('id')->firstOrFail()->id)->sole()->minutes);
    }

    public function test_in_progress_reestimate_revision_keeps_the_old_reservation_until_new_approval(): void
    {
        $first = $this->estimate(60);
        $this->approve($first);
        (new TransitionWorkRequest)->handle($this->operator, $this->request, WorkRequestStatus::InProgress);

        $this->actingAs($this->operator)->get(route('requests.show', $this->request))
            ->assertOk()->assertSee('data-test="write-estimate"', false);
        $this->post(route('requests.estimates.store', $this->request), [
            'base_version' => 1,
            'difficulty' => WorkDifficulty::Normal->value,
            'estimated_minutes' => 90,
            'rationale' => '진행 중 추가 범위',
            'included_scope' => '추가 범위',
            'excluded_scope' => '제외 범위',
            'scheduled_on' => today()->addWeek()->toDateString(),
            'usage_month' => today()->format('Y-m'),
        ])->assertRedirect();
        $second = $this->request->latestEstimateVersion()->firstOrFail();
        $this->post(route('requests.estimates.submit', [$this->request, $second]), ['confirmed' => 1])->assertRedirect();
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $this->request->fresh()->status);

        $this->actingAs($this->admin)->post(
            route('requests.estimates.revision', [$this->request, $second]),
            ['reason' => '추가 범위를 다시 조정해 주세요.'],
        )->assertRedirect();
        $this->assertSame(WorkRequestStatus::Estimating, $this->request->fresh()->status);
        $this->assertSame($first->id, $this->request->fresh()->approved_estimate_version_id);
        $this->assertSame(0, TimeLedgerEntry::where('source_type', 'estimate_replacement')->count());
        $this->assertSame(1, TimeLedgerEntry::where('type', TimeLedgerType::Reserve)->count());
        $this->actingAs($this->operator)->get(route('requests.show', $this->request))
            ->assertOk()->assertSee('data-test="write-estimate"', false);
    }

    private function estimate(int $minutes, int $monthOffset = 0): EstimateVersion
    {
        $assessment = (new AssessRequestPricing)->handle(
            $this->operator,
            $this->request,
            WorkDifficulty::Normal,
            $minutes,
            today(),
            '재견적 예약 교체 검증',
        );

        return (new SubmitEstimateVersion)->handle($this->operator, (new CreateEstimateVersion)->handle(
            $this->operator,
            $this->request,
            $assessment,
            '포함 범위',
            '제외 범위',
            today()->addWeek(),
            today()->startOfMonth()->addMonths($monthOffset),
        ));
    }

    private function replacement(int $minutes, int $monthOffset = 0): EstimateVersion
    {
        $estimate = $this->estimate($minutes, $monthOffset);
        (new TransitionWorkRequest)->handle($this->operator, $this->request, WorkRequestStatus::AwaitingApproval);

        return $estimate;
    }

    private function approve(EstimateVersion $estimate, ?string $key = null): EstimateApproval
    {
        return (new ApproveEstimateVersion)->handle(
            $this->admin,
            $estimate,
            $key ?? (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT,
            '127.0.0.1',
            'Replacement test',
        );
    }

    private function available(ContractMonth $month): int
    {
        return $month->entries()->get()->sum(
            fn (TimeLedgerEntry $entry): int => $entry->minutes * $entry->type->availableSign(),
        );
    }
}
