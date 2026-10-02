<?php

namespace Tests\Feature\Models;

use App\Actions\ConfirmContractSignature;
use App\Enums\ServiceContractStatus;
use App\Enums\ServiceContractType;
use App\Enums\WorkRequestStatus;
use App\Models\Company;
use App\Models\ServiceContract;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_stores_dates_enums_and_company_relationships(): void
    {
        $contract = ServiceContract::factory()->create();
        $this->assertSame(ServiceContractType::Maintenance, $contract->type);
        $this->assertSame(ServiceContractStatus::Draft, $contract->status);
        $this->assertSame(today()->toDateString(), $contract->starts_on->toDateString());
        $this->assertTrue($contract->company->serviceContracts()->first()->is($contract));
        $this->assertFalse($contract->permitsWork());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRecords(): array
    {
        return [
            'reversed dates' => [['starts_on' => '2026-10-02', 'ends_on' => '2026-10-01']],
            'invalid type' => [['type' => 'invalid']],
            'invalid status' => [['status' => 'invalid']],
            'partial signature' => [['signature_confirmed_at' => '2026-10-02 12:00:00']],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('invalidRecords')]
    public function test_database_rejects_invalid_contract_data(array $attributes): void
    {
        $contract = ServiceContract::factory()->create();
        $this->expectException(QueryException::class);
        ServiceContract::whereKey($contract->id)->update($attributes);
    }

    public function test_signed_contract_factory_uses_the_resolved_request_company(): void
    {
        $companyCount = Company::count();
        $request = WorkRequest::factory()->withSignedContract()->create();
        $contract = $request->serviceContract()->firstOrFail();

        $this->assertSame($companyCount + 1, Company::count());
        $this->assertSame($request->company_id, $contract->company_id);
        $this->assertSame($request->company_id, $request->project->company_id);
        $this->assertSame($request->company_id, $request->submitter->company_id);
    }

    public function test_signed_contract_factory_honors_an_explicit_company(): void
    {
        $company = Company::factory()->create();
        $companyCount = Company::count();

        foreach ([
            WorkRequest::factory()->for($company)->withSignedContract(),
            WorkRequest::factory()->withSignedContract()->for($company),
            WorkRequest::factory()->withSignedContract()->state(['company_id' => $company->id]),
        ] as $factory) {
            $request = $factory->create();
            $this->assertSame($company->id, $request->company_id);
            $this->assertSame($company->id, $request->serviceContract()->firstOrFail()->company_id);
        }

        $this->assertSame($companyCount, Company::count());
    }

    public function test_signature_confirmation_requires_private_document_and_is_idempotent(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('contracts/signed.pdf', 'signed contract fixture');
        $contract = ServiceContract::factory()->create(['document_path' => 'contracts/signed.pdf']);
        $actor = User::factory()->operator()->create();
        $action = new ConfirmContractSignature;
        $signed = $action->handle($actor, $contract);
        $this->assertTrue($signed->signatureConfirmer->is($actor));
        $this->assertTrue($signed->permitsWork());
        $this->travel(1)->hours();
        $again = $action->handle(User::factory()->operator()->create(), $contract);
        $this->assertTrue($again->signature_confirmed_at->equalTo($signed->signature_confirmed_at));
        $this->assertSame($actor->id, $again->signature_confirmed_by);
    }

    public function test_missing_contract_file_cannot_be_confirmed(): void
    {
        Storage::fake('local');
        $contract = ServiceContract::factory()->create(['document_path' => 'contracts/missing.pdf']);
        $this->expectException(ValidationException::class);
        (new ConfirmContractSignature)->handle(User::factory()->operator()->create(), $contract);
    }

    public function test_customer_cannot_confirm_signature_even_for_own_company(): void
    {
        $contract = ServiceContract::factory()->create();
        $customer = User::factory()->create(['company_id' => $contract->company_id]);
        $this->expectException(AuthorizationException::class);
        (new ConfirmContractSignature)->handle($customer, $contract);
    }

    public function test_contract_visibility_and_policy_isolate_companies_and_inactive_users(): void
    {
        $own = ServiceContract::factory()->create();
        $other = ServiceContract::factory()->create();
        $customer = User::factory()->create(['company_id' => $own->company_id]);
        $this->assertSame([$own->id], ServiceContract::visibleTo($customer)->pluck('id')->all());
        $this->assertTrue(Gate::forUser($customer)->allows('view', $own));
        $this->assertFalse(Gate::forUser($customer)->allows('view', $other));
        $inactive = User::factory()->operator()->inactive()->create();
        $this->assertFalse(Gate::forUser($inactive)->allows('confirmSignature', $own));
        $this->assertSame(0, ServiceContract::visibleTo($inactive)->count());
    }

    public function test_request_cannot_link_another_company_contract_even_with_query_builder(): void
    {
        $request = WorkRequest::factory()->create();
        $contract = ServiceContract::factory()->create();
        $this->expectException(QueryException::class);
        WorkRequest::whereKey($request->id)->update(['service_contract_id' => $contract->id]);
    }

    /** @return array<string, array{WorkRequestStatus}> */
    public static function workStatuses(): array
    {
        return ['queued' => [WorkRequestStatus::Queued], 'in progress' => [WorkRequestStatus::InProgress]];
    }

    #[DataProvider('workStatuses')]
    public function test_unsigned_contract_blocks_work_transition_and_leaves_no_activity(WorkRequestStatus $status): void
    {
        $request = WorkRequest::factory()->create();
        $contract = ServiceContract::factory()->create(['company_id' => $request->company_id]);
        try {
            $request->update(['service_contract_id' => $contract->id, 'status' => $status]);
            $this->fail('Unsigned contract allowed work.');
        } catch (ValidationException) {
            $this->assertSame(WorkRequestStatus::Received, $request->fresh()->status);
            $this->assertSame(1, $request->activities()->count());
        }
    }

    #[DataProvider('workStatuses')]
    public function test_signed_contract_allows_work_transition(WorkRequestStatus $status): void
    {
        $request = WorkRequest::factory()->withSignedContract()->create();
        $request->update(['status' => $status]);
        $this->assertSame($status, $request->fresh()->status);
        $contract = $request->serviceContract()->firstOrFail();
        $this->assertTrue($contract->workRequests()->first()->is($request));
    }

    public function test_missing_contract_blocks_creation_in_progress(): void
    {
        $this->expectException(ValidationException::class);
        WorkRequest::factory()->create(['status' => WorkRequestStatus::InProgress]);
    }

    public function test_expired_future_cancelled_and_wrong_company_contracts_do_not_permit_work(): void
    {
        $company = Company::factory()->create();
        $contracts = [
            ServiceContract::factory()->signed()->create(['company_id' => $company->id, 'starts_on' => today()->subYear(), 'ends_on' => today()->subDay()]),
            ServiceContract::factory()->signed()->create(['company_id' => $company->id, 'starts_on' => today()->addDay()]),
            ServiceContract::factory()->signed()->create(['company_id' => $company->id, 'status' => ServiceContractStatus::Cancelled]),
            ServiceContract::factory()->signed()->create(),
        ];
        foreach ($contracts as $contract) {
            $request = WorkRequest::factory()->for($company)->create();
            try {
                $request->update(['status' => WorkRequestStatus::Queued, 'service_contract_id' => $contract->id]);
                $this->fail('Invalid contract allowed work.');
            } catch (ValidationException) {
                $this->assertSame(WorkRequestStatus::Received, $request->fresh()->status);
            }
        }
    }
}
