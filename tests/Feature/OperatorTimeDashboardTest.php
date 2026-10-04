<?php

namespace Tests\Feature;

use App\Actions\AdjustContractMonth;
use App\Actions\ApproveEstimateVersion;
use App\Actions\ConfirmNonBillableWorkLog;
use App\Actions\ConfirmWorkLog;
use App\Actions\ProvideContractMonth;
use App\Actions\SaveWorkLogDraft;
use App\Enums\TimeLedgerType;
use App\Models\Company;
use App\Models\ServiceContract;
use App\Models\User;
use App\Services\OperatorCompanyTimeOverview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsContractTime;
use Tests\TestCase;

class OperatorTimeDashboardTest extends TestCase
{
    use BuildsContractTime, RefreshDatabase;

    public function test_operator_dashboard_shows_exact_company_and_month_totals_including_unconfigured_companies(): void
    {
        [$request, $operator, $admin, $month, $estimate] = $this->timeFixture(120, 60);
        $request->company->forceFill(['name' => '가나다 고객사'])->save();
        (new ApproveEstimateVersion)->handle($admin, $estimate, (string) Str::uuid(),
            ApproveEstimateVersion::APPROVAL_TEXT, '127.0.0.1', 'Dashboard time test');
        $billable = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '차감 작업',
            'minutes' => 30, 'is_billable' => true,
        ]);
        (new ConfirmWorkLog)->handle($operator, $billable, 1);
        $nonBillable = (new SaveWorkLogDraft)->handle($operator, $request, [
            'worked_on' => today()->toDateString(), 'description' => '무상 수정',
            'minutes' => 20, 'is_billable' => false, 'non_billable_reason' => '개발자 귀책',
        ]);
        (new ConfirmNonBillableWorkLog)->handle($operator, $nonBillable, 1);
        $provided = $month->entries()->where('type', TimeLedgerType::Provided)->sole();
        (new AdjustContractMonth)->handle($operator, $month, $provided, TimeLedgerType::AdjustIncrease,
            10, '추가 제공', (string) Str::uuid());
        (new AdjustContractMonth)->handle($operator, $month, $provided, TimeLedgerType::AdjustDecrease,
            5, '제공 정정', (string) Str::uuid());

        $secondCompany = Company::factory()->create(['name' => '라마바 고객사']);
        $secondContract = ServiceContract::factory()->signed()->for($secondCompany)->create();
        $secondMonth = (new ProvideContractMonth)->handle(
            $operator, $secondContract, today()->startOfMonth()->toDateString(), 80,
        );
        $unconfigured = Company::factory()->create(['name' => '사아자 미설정 고객사']);
        $inactive = Company::factory()->inactive()->create(['name' => '숨김 비활성 고객사']);

        $response = $this->actingAs($operator)->get(route('dashboard'))->assertOk()
            ->assertViewHas('companyTime', function (array $overview) use ($request, $secondMonth, $unconfigured): bool {
                $rows = $overview['rows']->keyBy(fn (array $row): int => $row['company']->id);

                return $rows->count() === 3
                    && $rows[$request->company_id]['provided_minutes'] === 120
                    && $rows[$request->company_id]['reserved_minutes'] === 30
                    && $rows[$request->company_id]['customer_charged_minutes'] === 30
                    && $rows[$request->company_id]['billable_minutes'] === 30
                    && $rows[$request->company_id]['non_billable_minutes'] === 20
                    && $rows[$request->company_id]['total_worked_minutes'] === 50
                    && $rows[$request->company_id]['available_minutes'] === 65
                    && $rows[$secondMonth->company_id]['provided_minutes'] === 80
                    && $rows[$secondMonth->company_id]['available_minutes'] === 80
                    && $rows[$unconfigured->id]['month_count'] === 0
                    && $overview['totals'] === [
                        'provided_minutes' => 200,
                        'reserved_minutes' => 30,
                        'customer_charged_minutes' => 30,
                        'billable_minutes' => 30,
                        'non_billable_minutes' => 20,
                        'total_worked_minutes' => 50,
                        'available_minutes' => 145,
                    ];
            });

        $response->assertSee('data-test="company-time-overview"', false)
            ->assertSee('data-test="company-time-row-'.$request->company_id.'"', false)
            ->assertSee('data-test="company-time-card-'.$request->company_id.'"', false)
            ->assertSee('가나다 고객사')->assertSee('라마바 고객사')->assertSee('사아자 미설정 고객사')
            ->assertSee('월 시간 미설정')->assertSee('200분')->assertSee('145분')
            ->assertDontSee($inactive->name);
    }

    public function test_overview_aggregates_multiple_contracts_but_only_for_the_selected_month(): void
    {
        [$request, $operator, , $month] = $this->timeFixture(100);
        $secondContract = ServiceContract::factory()->signed()->for($request->company)->create();
        (new ProvideContractMonth)->handle($operator, $secondContract, today()->startOfMonth()->toDateString(), 50);
        (new ProvideContractMonth)->handle($operator, $request->serviceContract,
            today()->startOfMonth()->addMonth()->toDateString(), 999);

        $overview = (new OperatorCompanyTimeOverview)->forMonth($operator, today());
        $row = $overview['rows']->where('company.id', $request->company_id)->sole();
        $this->assertSame($month->month->format('Y-m'), $overview['month']->format('Y-m'));
        $this->assertSame(2, $row['month_count']);
        $this->assertSame(150, $row['provided_minutes']);
        $this->assertSame(150, $row['available_minutes']);
        $this->assertSame(150, $overview['totals']['provided_minutes']);
        $this->assertSame(0, $overview['totals']['customer_charged_minutes']);
    }

    public function test_time_overview_is_system_only_and_customer_dashboard_receives_no_global_rows(): void
    {
        [$request, $operator, $admin] = $this->timeFixture();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertViewIs('dashboard.customer')
            ->assertViewMissing('companyTime')
            ->assertDontSee('data-test="company-time-overview"', false);
        try {
            (new OperatorCompanyTimeOverview)->forMonth($admin, today());
            $this->fail('Customer accessed the global company time overview.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        User::query()->whereKey($operator->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        (new OperatorCompanyTimeOverview)->forMonth($operator, today());
    }

    public function test_super_admin_sees_empty_state_when_no_active_companies_exist(): void
    {
        Company::factory()->inactive()->create();
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('dashboard'))->assertOk()
            ->assertViewHas('companyTime', fn (array $overview): bool => $overview['rows']->isEmpty()
                && $overview['totals']['provided_minutes'] === 0
                && $overview['totals']['available_minutes'] === 0)
            ->assertSee('data-test="company-time-empty"', false)
            ->assertSee('표시할 활성 고객사가 없습니다.');
    }

    public function test_overview_query_count_does_not_grow_with_company_count(): void
    {
        $operator = User::factory()->operator()->create();
        foreach (range(1, 8) as $number) {
            $company = Company::factory()->create(['name' => "쿼리 고객사 {$number}"]);
            $contract = ServiceContract::factory()->signed()->for($company)->create();
            (new ProvideContractMonth)->handle($operator, $contract, today()->startOfMonth()->toDateString(), 100);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $overview = (new OperatorCompanyTimeOverview)->forMonth($operator, today());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(8, $overview['rows']);
        $this->assertLessThanOrEqual(6, count($queries));
        $this->assertSame(800, $overview['totals']['provided_minutes']);
        $this->assertSame(800, $overview['totals']['available_minutes']);
    }
}
