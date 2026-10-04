<?php

namespace Tests\Feature;

use App\Actions\CreateCustomerADemoRequest;
use App\Actions\RunCustomerADemoWorkflow;
use App\Actions\SubmitCustomerADemoEstimate;
use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\ContractMonth;
use App\Models\EstimateApproval;
use App\Models\NotificationDelivery;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Tests\TestCase;

class CustomerADemoWorkflowCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_the_remaining_portfolio_demo_end_to_end(): void
    {
        $actor = User::factory()->superAdmin()->create(['email' => 'admin@example.test']);
        (new CreateCustomerADemoRequest)->handle($actor);
        (new SubmitCustomerADemoEstimate)->handle($actor);

        $this->demoCommand('all')
            ->expectsOutput('Customer A demo workflow step passed: all')
            ->assertSuccessful();

        $request = WorkRequest::query()->where('source_reference', CreateCustomerADemoRequest::REQUEST_REFERENCE)->sole();
        $approval = EstimateApproval::query()->sole();
        $log = WorkLog::query()->where('description', RunCustomerADemoWorkflow::WORK_LOG_DESCRIPTION)->sole();
        $entries = TimeLedgerEntry::query()->where('work_request_id', $request->id)->get();

        $this->assertSame(WorkRequestStatus::Completed, $request->status);
        $this->assertSame(RunCustomerADemoWorkflow::CUSTOMER_ADMIN_NAME, $approval->approver->name);
        $this->assertSame('admin+desk-demo-customer-a@example.test', $approval->approver->email);
        $this->assertSame(WorkLogStatus::Confirmed, $log->status);
        $this->assertSame(150, $log->minutes);
        $this->assertSame(180, $entries->where('type', TimeLedgerType::Reserve)->sum('minutes'));
        $this->assertSame(180, $entries->where('type', TimeLedgerType::Release)->sum('minutes'));
        $this->assertSame(150, $entries->where('type', TimeLedgerType::Usage)->sum('minutes'));
        $this->assertSame(30, $entries->where('source_type', 'work_request_status_change')->sum('minutes'));
        $this->assertSame(2, Company::query()->count());
        $this->assertSame(RunCustomerADemoWorkflow::CUSTOMER_B_MARKER, Company::query()->whereKeyNot($request->company_id)->sole()->notes);
        $this->assertGreaterThan(0, NotificationDelivery::query()->count());

        $admin = User::query()->where('name', RunCustomerADemoWorkflow::CUSTOMER_ADMIN_NAME)->sole();
        $month = ContractMonth::query()->findOrFail($entries->first()->contract_month_id);
        $this->actingAs($admin)->get(route('usage.show', $month))
            ->assertOk()->assertSee('150분')->assertSee('1,050분');
        $csv = $this->get(route('usage.export', $month));
        $csv->assertOk()->assertDownload();
        $this->assertStringContainsString('150', $csv->streamedContent());
    }

    public function test_repeating_all_does_not_duplicate_approval_usage_or_release(): void
    {
        $actor = User::factory()->superAdmin()->create();
        (new CreateCustomerADemoRequest)->handle($actor);
        (new SubmitCustomerADemoEstimate)->handle($actor);

        $this->demoCommand('all')->assertSuccessful();
        $counts = [
            EstimateApproval::query()->count(),
            WorkLog::query()->count(),
            TimeLedgerEntry::query()->count(),
            NotificationDelivery::query()->count(),
        ];
        $this->demoCommand('all')->assertSuccessful();

        $this->assertSame($counts, [
            EstimateApproval::query()->count(),
            WorkLog::query()->count(),
            TimeLedgerEntry::query()->count(),
            NotificationDelivery::query()->count(),
        ]);
    }

    public function test_steps_require_the_foundation_actor_and_production_force(): void
    {
        User::factory()->superAdmin()->create();
        $this->demoCommand('approve')->assertFailed();

        $previousEnvironment = $this->app->environment();
        $this->app->instance('env', 'production');
        try {
            $this->demoCommand('all')
                ->expectsOutput('Production demo workflow changes require --force.')
                ->assertFailed();
        } finally {
            $this->app->instance('env', $previousEnvironment);
        }
    }

    public function test_each_named_step_is_supported_and_unknown_step_fails(): void
    {
        $actor = User::factory()->superAdmin()->create();
        (new CreateCustomerADemoRequest)->handle($actor);
        (new SubmitCustomerADemoEstimate)->handle($actor);

        foreach (['approve', 'start', 'usage', 'complete', 'report', 'isolation'] as $step) {
            $this->demoCommand($step)->assertSuccessful();
        }
        $this->demoCommand('unknown')->assertFailed();
    }

    private function demoCommand(string $step): PendingCommand
    {
        $command = $this->artisan('desk:run-customer-a-demo', ['step' => $step]);
        if (! $command instanceof PendingCommand) {
            throw new RuntimeException('Expected a pending Artisan command.');
        }

        return $command;
    }
}
