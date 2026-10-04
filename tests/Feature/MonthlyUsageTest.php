<?php

namespace Tests\Feature;

use App\Actions\AdjustContractMonth;
use App\Actions\ApproveEstimateVersion;
use App\Actions\CancelWorkLogUsage;
use App\Actions\CloseContractMonth;
use App\Actions\ConfirmNonBillableWorkLog;
use App\Actions\ConfirmWorkLog;
use App\Actions\ProvideContractMonth;
use App\Actions\SaveWorkLogDraft;
use App\Enums\TimeLedgerType;
use App\Enums\UserRole;
use App\Models\ContractMonth;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class MonthlyUsageTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    /** @return array{WorkRequest, User, User, ContractMonth, WorkLog, WorkLog} */
    private function reportFixture(): array
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture(120, 60);
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(), ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Usage test');
        $cancelled = $this->log($operator, $request, 30, '취소된 작업');
        (new ConfirmWorkLog)->handle($operator, $cancelled, 1);
        (new CancelWorkLogUsage)->handle($operator, $cancelled, '내부 취소 검토 사유');
        $billable = $this->log($operator, $request, 10, '<script>private()</script> 차감 작업');
        (new ConfirmWorkLog)->handle($operator, $billable, 1);
        $free = $this->log($operator, $request, 20, '내부 비차감 작업', false);
        (new ConfirmNonBillableWorkLog)->handle($operator, $free, 1);
        $this->log($operator, $request, 5, '내부 초안');
        $provided = $month->entries()->where('type', TimeLedgerType::Provided)->sole();
        foreach ([TimeLedgerType::AdjustIncrease->value => 10, TimeLedgerType::AdjustDecrease->value => 5] as $type => $minutes) {
            (new AdjustContractMonth)->handle($operator, $month, $provided, TimeLedgerType::from($type), $minutes, '내부 조정 검토', (string) Str::uuid());
        }

        return [$request, $operator, $admin, $month, $cancelled, $free];
    }

    private function log(User $operator, WorkRequest $request, int $minutes, string $description, bool $billable = true): WorkLog
    {
        return (new SaveWorkLogDraft)->handle($operator, $request, ['worked_on' => today()->toDateString(),
            'minutes' => $minutes, 'description' => $description, 'is_billable' => $billable,
            'non_billable_reason' => $billable ? null : '내부 귀책 사유']);
    }

    public function test_customer_sees_own_contracts_for_selected_month_only(): void
    {
        [$request, $operator, $admin, $month] = $this->timeFixture();
        $second = ServiceContract::factory()->signed()->for($request->company)->create();
        $secondMonth = (new ProvideContractMonth)->handle($operator, $second, today()->startOfMonth()->toDateString(), 80);
        $nextMonth = (new ProvideContractMonth)->handle($operator, $request->serviceContract, today()->startOfMonth()->addMonth()->toDateString(), 777);
        $foreign = ServiceContract::factory()->signed()->create();
        $foreignMonth = (new ProvideContractMonth)->handle($operator, $foreign, today()->startOfMonth()->toDateString(), 999);
        $this->actingAs($admin)->get(route('usage.index'))->assertOk()
            ->assertViewHas('months', fn ($months): bool => $months->modelKeys() === [$month->id, $secondMonth->id])
            ->assertSee('100분')->assertSee('80분')->assertDontSee($foreign->company->name)
            ->assertDontSee('name="company_id"', false);
        $this->get(route('usage.index', ['month' => $nextMonth->month->format('Y-m')]))->assertOk()
            ->assertViewHas('months', fn ($months): bool => $months->modelKeys() === [$nextMonth->id]);
        $this->get(route('usage.index', ['company_id' => $foreign->company_id]))->assertNotFound();
        foreach (['usage', 'ledger'] as $tab) {
            $this->get(route('usage.show', [$foreignMonth, 'tab' => $tab]))->assertForbidden()->assertDontSee($foreign->company->name);
        }
    }

    public function test_usage_totals_include_cancellation_and_adjustments_without_exposing_internal_work(): void
    {
        [$request, $operator, $admin, $month, $cancelled, $free] = $this->reportFixture();
        $url = route('usage.show', $month);
        $response = $this->actingAs($admin)->get($url)->assertOk()->assertViewHas('totals', fn (array $totals): bool => $totals['provided'] === 120 && $totals['net_usage'] === 10 && $totals['remaining_reserved'] === 20
            && $totals['available'] === 95 && $totals['adjust_increase'] === 10 && $totals['adjust_decrease'] === 5);
        $response->assertSee('사용 취소')->assertSee('취소 전 차감 30분')
            ->assertSee(route('requests.show', $request))->assertSee('&lt;script&gt;private()&lt;/script&gt;', false)
            ->assertDontSee('<script>private()</script>', false)->assertDontSee('내부 초안')
            ->assertDontSee('내부 비차감 작업')->assertDontSee('내부 귀책 사유')
            ->assertViewHas('logs', fn ($logs): bool => $logs->total() === 2)
            ->assertViewHas('cancelledLogIds', fn (array $ids): bool => $ids === [$cancelled->id]);
        $this->actingAs($operator)->get($url)->assertOk()->assertSee('내부 귀책 사유')
            ->assertSee('data-test="usage-log-'.$free->id.'"', false)->assertDontSee('내부 초안')
            ->assertViewHas('logs', fn ($logs): bool => $logs->total() === 3);
    }

    public function test_ledger_filters_do_not_change_month_totals_and_internal_audit_is_operator_only(): void
    {
        [, $operator, $admin, $month] = $this->reportFixture();
        $this->actingAs($admin)->get(route('usage.show', [$month, 'tab' => 'ledger']))->assertOk()
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 9)
            ->assertDontSee('내부 조정 검토')->assertDontSee('내부 취소 검토 사유')->assertDontSee('내부 귀책 사유');
        foreach (TimeLedgerType::cases() as $type) {
            $this->get(route('usage.show', [$month, 'tab' => 'ledger', 'type' => $type->value]))->assertOk()
                ->assertViewHas('entries', fn ($entries): bool => $entries->isNotEmpty() && $entries->every(fn ($entry): bool => $entry->type === $type))
                ->assertViewHas('totals', fn (array $totals): bool => $totals['available'] === 95);
        }
        $this->actingAs($operator)->get(route('usage.show', [$month, 'tab' => 'ledger']))->assertOk()
            ->assertSee('내부 조정 검토')->assertSee('내부 취소 검토 사유')->assertSee($operator->name);
    }

    /** @return array<string, array{UserRole, bool}> */
    public static function roles(): array
    {
        return ['super' => [UserRole::SuperAdmin, true], 'operator' => [UserRole::Operator, true],
            'customer admin' => [UserRole::CustomerAdmin, true], 'customer user' => [UserRole::CustomerUser, false]];
    }

    #[DataProvider('roles')]
    public function test_role_permissions_match_navigation_and_direct_routes(UserRole $role, bool $allowed): void
    {
        [$request, , , $month] = $this->timeFixture();
        $user = User::factory()->create(['role' => $role, 'company_id' => $role->isSystemRole() ? null : $request->company_id]);
        $dashboard = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        if ($allowed) {
            $dashboard->assertSee(route('usage.index'));
        } else {
            $dashboard->assertDontSee(route('usage.index'))->assertSee('이번 달 자사 시간 현황');
        }
        foreach ([route('usage.index'), route('usage.show', $month), route('usage.show', [$month, 'tab' => 'ledger'])] as $url) {
            $this->get($url)->assertStatus($allowed ? 200 : 403);
        }
    }

    public function test_inactive_or_revoked_users_are_denied_using_current_database_permissions(): void
    {
        [$request, $operator, $admin, $month] = $this->timeFixture();
        $this->actingAs($operator);
        User::query()->whereKey($operator->id)->update(['is_active' => false]);
        $this->get(route('usage.index'))->assertForbidden();
        $this->get(route('usage.show', $month))->assertForbidden();
        $this->actingAs($admin);
        User::query()->whereKey($admin->id)->update(['role' => UserRole::CustomerUser]);
        $this->get(route('usage.index'))->assertForbidden();
        $this->get(route('usage.show', $month))->assertForbidden();
        $activeAdmin = User::factory()->customerAdmin()->for($request->company)->create();
        $request->company->update(['status' => 'inactive']);
        $this->actingAs($activeAdmin)->get(route('usage.index'))->assertForbidden();
        $this->get(route('usage.show', $month))->assertForbidden();
    }

    public function test_operator_filters_companies_and_month_lists_are_paginated(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        for ($i = 0; $i < 16; $i++) {
            $contract = ServiceContract::factory()->signed()->create();
            (new ProvideContractMonth)->handle($operator, $contract, today()->startOfMonth()->toDateString(), 80);
        }
        $this->actingAs($operator)->get(route('usage.index'))->assertOk()
            ->assertViewHas('months', fn ($months): bool => $months->total() === 17 && $months->count() === 15);
        $this->get(route('usage.index', ['company_id' => $request->company_id]))->assertOk()
            ->assertViewHas('months', fn ($months): bool => $months->modelKeys() === [$month->id]);
        $this->get(route('usage.index', ['page' => 2]))->assertOk()
            ->assertViewHas('months', fn ($months): bool => $months->total() === 17 && $months->count() === 2);
    }

    public function test_ledger_pagination_uses_complete_aggregate_and_preserves_filter(): void
    {
        [, $operator, $admin, $month] = $this->timeFixture();
        $provided = $month->entries()->sole();
        for ($i = 0; $i < 23; $i++) {
            (new AdjustContractMonth)->handle($operator, $month, $provided, TimeLedgerType::AdjustIncrease, 1, '추가 제공', (string) Str::uuid());
        }
        $this->actingAs($admin)->get(route('usage.show', [$month, 'tab' => 'ledger', 'type' => 'adjust_increase']))->assertOk()
            ->assertViewHas('entries', fn (LengthAwarePaginator $entries): bool => $entries->count() === 20 && $entries->total() === 23
                && str_contains($entries->nextPageUrl(), 'type=adjust_increase') && str_contains($entries->nextPageUrl(), 'tab=ledger'))
            ->assertViewHas('totals', fn (array $totals): bool => $totals['available'] === 123);
        $this->get(route('usage.show', [$month, 'tab' => 'ledger', 'type' => 'adjust_increase', 'page' => 2]))->assertOk()
            ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 3)
            ->assertViewHas('totals', fn (array $totals): bool => $totals['available'] === 123);
    }

    public function test_closed_month_shows_later_adjustments_in_its_current_balance(): void
    {
        [, $operator, $admin, $month] = $this->timeFixture();
        $this->travelTo($month->month->copy()->addMonth()->startOfMonth());
        (new CloseContractMonth)->handle($operator, $month);
        (new AdjustContractMonth)->handle($operator, $month, $month->entries()->sole(), TimeLedgerType::AdjustIncrease, 15, '마감 후 정정', (string) Str::uuid());
        $this->actingAs($admin)->get(route('usage.show', [$month, 'tab' => 'ledger']))->assertOk()->assertSee('마감 시각')
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 2)
            ->assertViewHas('totals', fn (array $totals): bool => $totals['available'] === 115);
        $this->get(route('usage.index', ['month' => $month->month->format('Y-m')]))->assertOk()->assertSee('115분');
    }

    public function test_empty_states_invalid_filters_and_unknown_month(): void
    {
        [, , $admin, $month] = $this->timeFixture();
        $this->get(route('usage.index'))->assertRedirect(route('login'));
        $this->actingAs($admin)->get(route('usage.index', ['month' => '1000-01']))->assertOk()->assertSee('선택한 월에 등록된 계약시간이 없습니다.');
        $this->get(route('usage.show', $month))->assertOk()->assertSee('이 월에 확정된 차감 작업 내역이 없습니다.');
        $this->get(route('usage.show', [$month, 'tab' => 'ledger', 'type' => 'usage']))->assertOk()->assertSee('해당 종류의 원장 내역이 없습니다.');
        foreach (['2026-13', '0000-01', '2026-1', ['2026-10']] as $invalid) {
            $this->from(route('usage.index'))->get(route('usage.index', ['month' => $invalid]))->assertSessionHasErrors('month');
        }
        $this->get(route('usage.index', ['company_id' => 'bad', 'page' => -1]))->assertSessionHasErrors(['company_id', 'page']);
        $this->get(route('usage.show', [$month, 'tab' => 'unknown', 'type' => 'unknown']))->assertSessionHasErrors(['tab', 'type']);
        $this->get(route('usage.show', 999999))->assertNotFound();
    }

    public function test_usage_pagination_excludes_drafts_and_other_contracts_and_months(): void
    {
        [$request, $operator, , $month] = $this->timeFixture();
        for ($i = 0; $i < 23; $i++) {
            $log = $this->log($operator, $request, 1, '확정 작업 '.$i, false);
            (new ConfirmNonBillableWorkLog)->handle($operator, $log, 1);
        }
        $this->log($operator, $request, 1, '제외할 초안', false);
        $otherRequest = WorkRequest::factory()->withSignedContract()->for($request->company)->create();
        (new ProvideContractMonth)->handle($operator, $otherRequest->serviceContract, today()->startOfMonth()->toDateString(), 100);
        $other = $this->log($operator, $otherRequest, 1, '다른 계약 작업', false);
        (new ConfirmNonBillableWorkLog)->handle($operator, $other, 1);
        $this->travelTo($month->month->copy()->addMonth()->startOfMonth());
        $date = today()->toDateString();
        (new ProvideContractMonth)->handle($operator, $request->serviceContract, $date, 100);
        $previous = (new SaveWorkLogDraft)->handle($operator, $request, ['worked_on' => $date, 'description' => '다른 월 작업',
            'minutes' => 1, 'is_billable' => false, 'non_billable_reason' => '내부 검증']);
        (new ConfirmNonBillableWorkLog)->handle($operator, $previous, 1);
        $this->actingAs($operator)->get(route('usage.show', $month))->assertOk()
            ->assertViewHas('logs', fn ($logs): bool => $logs->total() === 23 && $logs->count() === 20)
            ->assertDontSee('제외할 초안')->assertDontSee('다른 계약 작업')->assertDontSee('다른 월 작업');
        $this->get(route('usage.show', [$month, 'page' => 2]))->assertOk()
            ->assertViewHas('logs', fn ($logs): bool => $logs->total() === 23 && $logs->count() === 3);
    }
}
