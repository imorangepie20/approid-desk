<?php

namespace Tests\Feature;

use App\Actions\CreateCustomerADemoRequest;
use App\Enums\IntakeChannel;
use App\Enums\ServiceContractStatus;
use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\ContractMonth;
use App\Models\Project;
use App\Models\ServiceContract;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerADemoRequestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_customer_a_request_and_time_foundation(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $this->artisan('desk:create-customer-a-demo-request')
            ->expectsOutput('Customer A demo request is ready.')
            ->assertSuccessful();

        $request = WorkRequest::query()->sole();
        $contract = ServiceContract::query()->sole();
        $month = ContractMonth::query()->sole();

        $this->assertSame('데모 고객사 A', Company::query()->sole()->name);
        $this->assertSame(CreateCustomerADemoRequest::COMPANY_MARKER, Company::query()->sole()->notes);
        $this->assertSame('고객 포털 개선', Project::query()->sole()->name);
        $this->assertSame(CreateCustomerADemoRequest::PROJECT_MARKER, Project::query()->sole()->technical_notes);
        $this->assertSame($actor->id, $contract->signature_confirmed_by);
        $this->assertSame(ServiceContractStatus::Active, $contract->status);
        $this->assertTrue($contract->permitsWork());
        $this->assertSame(CreateCustomerADemoRequest::PROVIDED_MINUTES, $month->provided_minutes);
        $this->assertSame($contract->id, $month->service_contract_id);
        $this->assertSame(TimeLedgerType::Provided, TimeLedgerEntry::query()->sole()->type);
        $this->assertSame($actor->id, TimeLedgerEntry::query()->sole()->actor_id);
        $this->assertSame('고객 포털 알림 설정 개선', $request->title);
        $this->assertSame(CreateCustomerADemoRequest::REQUEST_REFERENCE, $request->source_reference);
        $this->assertSame(IntakeChannel::Other, $request->intake_channel);
        $this->assertSame(WorkRequestStatus::Received, $request->status);
        $this->assertSame($contract->id, $request->service_contract_id);
        $this->assertSame($actor->id, $request->submitted_by);
        $this->assertSame(1, WorkRequestActivity::query()->count());
    }

    public function test_repeating_the_command_returns_the_same_demo_records(): void
    {
        User::factory()->superAdmin()->create();

        $this->artisan('desk:create-customer-a-demo-request')->assertSuccessful();
        $ids = [
            Company::query()->sole()->id,
            Project::query()->sole()->id,
            ServiceContract::query()->sole()->id,
            ContractMonth::query()->sole()->id,
            WorkRequest::query()->sole()->id,
        ];

        $this->travel(1)->month();
        $this->artisan('desk:create-customer-a-demo-request')->assertSuccessful();

        $this->assertSame($ids, [
            Company::query()->sole()->id,
            Project::query()->sole()->id,
            ServiceContract::query()->sole()->id,
            ContractMonth::query()->sole()->id,
            WorkRequest::query()->sole()->id,
        ]);
        $this->assertSame(1, TimeLedgerEntry::query()->count());
        $this->assertSame(1, WorkRequestActivity::query()->count());
    }

    public function test_it_requires_an_explicit_actor_when_multiple_system_users_are_active(): void
    {
        User::factory()->superAdmin()->create(['email' => 'admin@example.test']);
        $operator = User::factory()->operator()->create(['email' => 'operator@example.test']);

        $this->artisan('desk:create-customer-a-demo-request')
            ->expectsOutput('Specify --actor when there is not exactly one active system user.')
            ->assertFailed();
        $this->assertSame(0, WorkRequest::query()->count());

        $this->artisan('desk:create-customer-a-demo-request', ['--actor' => strtoupper($operator->email)])
            ->assertSuccessful();
        $this->assertSame($operator->id, WorkRequest::query()->sole()->submitted_by);
    }

    public function test_production_requires_force_before_writing_demo_data(): void
    {
        User::factory()->superAdmin()->create();
        $previousEnvironment = $this->app->environment();
        $this->app->instance('env', 'production');

        try {
            $this->artisan('desk:create-customer-a-demo-request')
                ->expectsOutput('Production demo data creation requires --force.')
                ->assertFailed();
        } finally {
            $this->app->instance('env', $previousEnvironment);
        }

        $this->assertSame(0, WorkRequest::query()->count());
    }
}
