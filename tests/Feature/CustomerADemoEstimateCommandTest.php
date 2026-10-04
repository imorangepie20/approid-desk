<?php

namespace Tests\Feature;

use App\Actions\CreateCustomerADemoRequest;
use App\Actions\SubmitCustomerADemoEstimate;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\NotificationDelivery;
use App\Models\PricingAssessment;
use App\Models\PricingRule;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerADemoEstimateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_submits_the_180_minute_demo_estimate_through_the_real_workflow(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $request = (new CreateCustomerADemoRequest)->handle($actor);

        $this->artisan('desk:submit-customer-a-demo-estimate')
            ->expectsOutput('Customer A demo estimate is submitted.')
            ->assertSuccessful();

        $assessment = PricingAssessment::query()->sole();
        $estimate = EstimateVersion::query()->sole();
        $changes = WorkRequestStatusChange::query()->orderBy('id')->get();

        $this->assertSame(SubmitCustomerADemoEstimate::HOURLY_RATE, PricingRule::query()->sole()->hourly_rate);
        $this->assertSame(SubmitCustomerADemoEstimate::RULE_CRITERIA, PricingRule::query()->sole()->urgent_criteria);
        $this->assertSame(SubmitCustomerADemoEstimate::ESTIMATED_MINUTES, $assessment->estimated_minutes);
        $this->assertSame(180000, $assessment->amount);
        $this->assertSame($actor->id, $assessment->assessed_by);
        $this->assertSame(1, $estimate->version);
        $this->assertSame(SubmitCustomerADemoEstimate::ESTIMATED_MINUTES, $estimate->estimated_minutes);
        $this->assertSame(180000, $estimate->amount);
        $this->assertSame(SubmitCustomerADemoEstimate::INCLUDED_SCOPE, $estimate->included_scope);
        $this->assertSame(SubmitCustomerADemoEstimate::EXCLUDED_SCOPE, $estimate->excluded_scope);
        $this->assertSame(SubmitCustomerADemoEstimate::RATIONALE, $estimate->pricing_rationale);
        $this->assertNotNull($estimate->submitted_at);
        $this->assertTrue($estimate->usage_month->equalTo($request->serviceContract->starts_on->copy()->startOfMonth()));
        $this->assertSame(WorkRequestStatus::AwaitingApproval, $request->fresh()->status);
        $this->assertSame([WorkRequestStatus::Estimating, WorkRequestStatus::AwaitingApproval], $changes->pluck('to_status')->all());
        $this->assertSame(0, EstimateApproval::query()->count());
        $this->assertSame(0, TimeLedgerEntry::query()->where('type', TimeLedgerType::Reserve->value)->count());
        $this->assertSame(1, TimeLedgerEntry::query()->where('type', TimeLedgerType::Provided->value)->count());
        $this->assertSame(0, NotificationDelivery::query()->count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_repeating_the_command_keeps_one_submitted_estimate_and_no_reservation(): void
    {
        $actor = User::factory()->superAdmin()->create();
        (new CreateCustomerADemoRequest)->handle($actor);

        $this->artisan('desk:submit-customer-a-demo-estimate')->assertSuccessful();
        $estimateId = EstimateVersion::query()->sole()->id;
        $submittedAt = EstimateVersion::query()->sole()->submitted_at;

        $this->travel(1)->month();
        $this->artisan('desk:submit-customer-a-demo-estimate')->assertSuccessful();

        $this->assertSame($estimateId, EstimateVersion::query()->sole()->id);
        $this->assertTrue($submittedAt?->equalTo(EstimateVersion::query()->sole()->submitted_at) ?? false);
        $this->assertSame(1, PricingRule::query()->count());
        $this->assertSame(1, PricingAssessment::query()->count());
        $this->assertSame(2, WorkRequestStatusChange::query()->count());
        $this->assertSame(0, TimeLedgerEntry::query()->where('type', TimeLedgerType::Reserve->value)->count());
    }

    public function test_it_uses_one_existing_applicable_price_rule_without_overwriting_it(): void
    {
        $actor = User::factory()->operator()->create();
        (new CreateCustomerADemoRequest)->handle($actor);
        $rule = PricingRule::factory()->create(['hourly_rate' => 90000]);

        $this->artisan('desk:submit-customer-a-demo-estimate')->assertSuccessful();

        $this->assertSame($rule->id, PricingAssessment::query()->sole()->pricing_rule_id);
        $this->assertSame(270000, EstimateVersion::query()->sole()->amount);
        $this->assertSame(1, PricingRule::query()->count());
    }

    public function test_it_requires_the_4_27_foundation_and_production_force(): void
    {
        User::factory()->superAdmin()->create();

        $this->artisan('desk:submit-customer-a-demo-estimate')->assertFailed();
        $this->assertSame(0, EstimateVersion::query()->count());

        $previousEnvironment = $this->app->environment();
        $this->app->instance('env', 'production');

        try {
            $this->artisan('desk:submit-customer-a-demo-estimate')
                ->expectsOutput('Production demo data changes require --force.')
                ->assertFailed();
        } finally {
            $this->app->instance('env', $previousEnvironment);
        }

        $this->assertSame(0, WorkRequest::query()->count());
    }
}
