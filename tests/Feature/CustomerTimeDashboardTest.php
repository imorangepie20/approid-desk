<?php

namespace Tests\Feature;

use App\Actions\AdjustContractMonth;
use App\Actions\ApproveEstimateVersion;
use App\Actions\ConfirmNonBillableWorkLog;
use App\Actions\ConfirmWorkLog;
use App\Actions\ProvideContractMonth;
use App\Actions\SaveWorkLogDraft;
use App\Enums\ServiceContractType;
use App\Enums\TimeLedgerType;
use App\Models\Company;
use App\Models\ServiceContract;
use App\Models\User;
use App\Services\CustomerCompanyTimeOverview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class CustomerTimeDashboardTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    public function test_customer_dashboard_shows_exact_own_company_totals_and_contract_breakdown(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture(120, 60);
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Customer dashboard time test');
        $billable = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '고객 차감 작업',
            'minutes' => 30, 'is_billable' => true,
        ]);
        (new ConfirmWorkLog)->handle($operator, $billable, 1);
        $nonBillable = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '내부 무상 수정',
            'minutes' => 20, 'is_billable' => false, 'non_billable_reason' => '개발자 귀책',
        ]);
        (new ConfirmNonBillableWorkLog)->handle($operator, $nonBillable, 1);
        $provided = $month->entries()->where('type', TimeLedgerType::Provided)->sole();
        (new AdjustContractMonth)->handle($operator, $month, $provided, TimeLedgerType::AdjustIncrease,
            10, '추가 제공', (string) Str::uuid());
        (new AdjustContractMonth)->handle($operator, $month, $provided, TimeLedgerType::AdjustDecrease,
            5, '제공 정정', (string) Str::uuid());

        $secondContract = ServiceContract::factory()->signed()->for($request->company)->create([
            'type' => ServiceContractType::Development,
        ]);
        (new ProvideContractMonth)->handle($operator, $secondContract, today()->startOfMonth()->toDateString(), 80);
        (new ProvideContractMonth)->handle(
            $operator, $request->serviceContract, today()->startOfMonth()->addMonth()->toDateString(), 777,
        );
        $foreignCompany = Company::factory()->create(['name' => '노출 금지 타사']);
        $foreignContract = ServiceContract::factory()->signed()->for($foreignCompany)->create();
        (new ProvideContractMonth)->handle($operator, $foreignContract, today()->startOfMonth()->toDateString(), 999);

        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertViewHas('customerTime', function (array $overview) use ($request, $secondContract): bool {
                $rows = $overview['rows']->keyBy(fn (array $row): int => $row['contract']->id);

                return $overview['company']->is($request->company)
                    && $rows->count() === 2
                    && $rows[$request->service_contract_id]['provided_minutes'] === 120
                    && $rows[$request->service_contract_id]['reserved_minutes'] === 30
                    && $rows[$request->service_contract_id]['customer_charged_minutes'] === 30
                    && $rows[$request->service_contract_id]['available_minutes'] === 65
                    && $rows[$secondContract->id]['provided_minutes'] === 80
                    && $rows[$secondContract->id]['available_minutes'] === 80
                    && $overview['totals'] === [
                        'provided_minutes' => 200,
                        'reserved_minutes' => 30,
                        'customer_charged_minutes' => 30,
                        'available_minutes' => 145,
                    ];
            });

        $response->assertSee('data-test="customer-time-overview"', false)
            ->assertSee('data-test="customer-time-row-'.$request->service_contract_id.'"', false)
            ->assertSee('data-test="customer-time-card-'.$secondContract->id.'"', false)
            ->assertSee('200분')->assertSee('145분')->assertSee('신규 개발')
            ->assertDontSee('비차감')->assertDontSee('노출 금지 타사')
            ->assertDontSee('계약 #'.$foreignContract->id);

        $ordinaryCustomer = User::factory()->customerUser()->for($request->company)->create();
        $this->actingAs($ordinaryCustomer)->get(route('dashboard'))->assertOk()
            ->assertViewHas('customerTime', fn (array $overview): bool => $overview['totals']['provided_minutes'] === 200
                && $overview['totals']['customer_charged_minutes'] === 30);
    }

    public function test_customer_overview_is_customer_only_and_rechecks_current_access(): void
    {
        [$request, $operator, $admin] = $this->timeFixture();
        $service = new CustomerCompanyTimeOverview;

        try {
            $service->forMonth($operator, today());
            $this->fail('System user accessed the customer company overview.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        User::query()->whereKey($admin->id)->update(['is_active' => false]);
        try {
            $service->forMonth($admin, today());
            $this->fail('Inactive customer accessed the company overview.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $activeCustomer = User::factory()->customerUser()->for($request->company)->create();
        $request->company->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        $service->forMonth($activeCustomer, today());
    }

    public function test_customer_dashboard_shows_unconfigured_state_with_zero_totals(): void
    {
        $company = Company::factory()->create(['name' => '시간 미설정 고객사']);
        $customer = User::factory()->customerAdmin()->for($company)->create();

        $this->actingAs($customer)->get(route('dashboard'))->assertOk()
            ->assertViewHas('customerTime', fn (array $overview): bool => $overview['company']->is($company)
                && $overview['rows']->isEmpty()
                && $overview['totals'] === [
                    'provided_minutes' => 0,
                    'reserved_minutes' => 0,
                    'customer_charged_minutes' => 0,
                    'available_minutes' => 0,
                ])
            ->assertSee('data-test="customer-time-unconfigured"', false)
            ->assertSee('이번 달 제공시간이 설정되지 않았습니다.');
    }

    public function test_customer_overview_excludes_other_months(): void
    {
        [$request, , $admin] = $this->timeFixture(100);
        $operator = User::factory()->operator()->create();
        (new ProvideContractMonth)->handle(
            $operator, $request->serviceContract, today()->startOfMonth()->addMonth()->toDateString(), 900,
        );

        $overview = (new CustomerCompanyTimeOverview)->forMonth($admin, today());

        $this->assertSame(today()->format('Y-m'), $overview['month']->format('Y-m'));
        $this->assertCount(1, $overview['rows']);
        $this->assertSame(100, $overview['totals']['provided_minutes']);
        $this->assertSame(100, $overview['totals']['available_minutes']);
    }

    public function test_customer_overview_query_count_does_not_grow_with_contract_count(): void
    {
        $company = Company::factory()->create();
        $operator = User::factory()->operator()->create();
        $customer = User::factory()->customerUser()->for($company)->create();
        foreach (range(1, 8) as $number) {
            $contract = ServiceContract::factory()->signed()->for($company)->create();
            (new ProvideContractMonth)->handle(
                $operator, $contract, today()->startOfMonth()->toDateString(), $number * 10,
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $overview = (new CustomerCompanyTimeOverview)->forMonth($customer, today());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(8, $overview['rows']);
        $this->assertLessThanOrEqual(5, count($queries));
        $this->assertSame(360, $overview['totals']['provided_minutes']);
        $this->assertSame(360, $overview['totals']['available_minutes']);
    }
}
