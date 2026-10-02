<?php

namespace Tests\Feature\Policies;

use App\Actions\CreateUserInvitation;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\WorkRequest;
use App\Models\WorkRequestComment;
use App\Policies\UserInvitationPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CustomerRolePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_permissions_are_registered_as_gate_abilities(): void
    {
        $customerAdmin = User::factory()->customerAdmin()->create();
        $customerUser = User::factory()->customerUser()->create();

        $this->assertTrue(Gate::forUser($customerAdmin)->allows(Permission::ManageCompanyUsers->value));
        $this->assertTrue(Gate::forUser($customerAdmin)->allows(Permission::ApproveEstimates->value));
        $this->assertFalse(Gate::forUser($customerUser)->allows(Permission::ManageCompanyUsers->value));
        $this->assertFalse(Gate::forUser($customerUser)->allows(Permission::ApproveEstimates->value));
    }

    public function test_user_and_invitation_models_discover_their_policies(): void
    {
        $this->assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class));
        $this->assertInstanceOf(UserInvitationPolicy::class, Gate::getPolicyFor(UserInvitation::class));
    }

    public function test_customer_admin_can_manage_only_users_from_their_company(): void
    {
        $company = Company::factory()->create();
        $customerAdmin = User::factory()->customerAdmin()->for($company)->create();
        $ownUser = User::factory()->for($company)->create();
        $otherUser = User::factory()->create();

        $this->assertTrue(Gate::forUser($customerAdmin)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($customerAdmin)->allows('view', $ownUser));
        $this->assertTrue(Gate::forUser($customerAdmin)->allows('update', $ownUser));
        $this->assertFalse(Gate::forUser($customerAdmin)->allows('view', $otherUser));
        $this->assertFalse(Gate::forUser($customerAdmin)->allows('update', $otherUser));
    }

    public function test_customer_user_cannot_manage_users(): void
    {
        $company = Company::factory()->create();
        $customerUser = User::factory()->customerUser()->for($company)->create();
        $otherUser = User::factory()->for($company)->create();

        $this->assertFalse(Gate::forUser($customerUser)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($customerUser)->allows('view', $otherUser));
        $this->assertFalse(Gate::forUser($customerUser)->allows('update', $otherUser));
    }

    public function test_customer_admin_can_invite_to_their_company_but_not_another_company(): void
    {
        $company = Company::factory()->create();
        $customerAdmin = User::factory()->customerAdmin()->for($company)->create();
        $otherCompany = Company::factory()->create();

        $created = app(CreateUserInvitation::class)->handle(
            inviter: $customerAdmin,
            company: $company,
            email: 'own-company-user@example.com',
            role: UserRole::CustomerUser,
            expiresAt: now()->addDay(),
        );

        $this->assertTrue($created->invitation->company->is($company));

        $this->expectException(AuthorizationException::class);

        app(CreateUserInvitation::class)->handle(
            inviter: $customerAdmin,
            company: $otherCompany,
            email: 'other-company-user@example.com',
            role: UserRole::CustomerUser,
            expiresAt: now()->addDay(),
        );
    }

    public function test_request_create_and_update_permissions_follow_customer_roles(): void
    {
        $company = Company::factory()->create();
        $customerAdmin = User::factory()->customerAdmin()->for($company)->create();
        $customerUser = User::factory()->customerUser()->for($company)->create();
        $otherCustomerAdmin = User::factory()->customerAdmin()->create();
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->for($company)->create();

        $this->assertTrue(Gate::forUser($customerAdmin)->allows('create', WorkRequest::class));
        $this->assertTrue(Gate::forUser($customerUser)->allows('create', WorkRequest::class));
        $this->assertTrue(Gate::forUser($operator)->allows('create', WorkRequest::class));
        $this->assertTrue(Gate::forUser($customerAdmin)->allows('update', $request));
        $this->assertFalse(Gate::forUser($customerUser)->allows('update', $request));
        $this->assertFalse(Gate::forUser($otherCustomerAdmin)->allows('update', $request));
        $this->assertTrue(Gate::forUser($operator)->allows('update', $request));
    }

    public function test_customer_users_can_comment_only_on_requests_from_their_company(): void
    {
        $company = Company::factory()->create();
        $customerUser = User::factory()->customerUser()->for($company)->create();
        $ownRequest = WorkRequest::factory()->for($company)->create();
        $otherRequest = WorkRequest::factory()->create();

        $this->assertTrue(Gate::forUser($customerUser)->allows(
            'create',
            [WorkRequestComment::class, $ownRequest],
        ));
        $this->assertFalse(Gate::forUser($customerUser)->allows(
            'create',
            [WorkRequestComment::class, $otherRequest],
        ));
    }

    public function test_inactive_accounts_are_denied_role_and_model_abilities(): void
    {
        $company = Company::factory()->create();
        $inactiveAdmin = User::factory()->customerAdmin()->inactive()->for($company)->create();
        $request = WorkRequest::factory()->for($company)->create();

        $this->assertFalse(Gate::forUser($inactiveAdmin)->allows(Permission::ApproveEstimates->value));
        $this->assertFalse(Gate::forUser($inactiveAdmin)->allows('create', WorkRequest::class));
        $this->assertFalse(Gate::forUser($inactiveAdmin)->allows('update', $request));
    }
}
