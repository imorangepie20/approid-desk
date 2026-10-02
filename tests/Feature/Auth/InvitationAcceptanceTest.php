<?php

namespace Tests\Feature\Auth;

use App\Actions\AcceptUserInvitation;
use App\Actions\CreateUserInvitation;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_a_customer_invitation_without_storing_the_plain_token(): void
    {
        $operator = User::factory()->operator()->create();
        $company = Company::factory()->create();
        $expiresAt = now()->addDays(7);

        $created = app(CreateUserInvitation::class)->handle(
            inviter: $operator,
            company: $company,
            email: ' Customer.Admin@Example.com ',
            role: UserRole::CustomerAdmin,
            expiresAt: $expiresAt,
        );

        $this->assertSame('customer.admin@example.com', $created->invitation->email);
        $this->assertSame(UserRole::CustomerAdmin, $created->invitation->role);
        $this->assertTrue($created->invitation->company->is($company));
        $this->assertTrue($created->invitation->inviter->is($operator));
        $this->assertSame(hash('sha256', $created->token), $created->invitation->token_hash);
        $this->assertNotSame($created->token, $created->invitation->token_hash);
        $this->assertArrayNotHasKey('token_hash', $created->invitation->toArray());
        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $created->token]);
        $this->assertSame($expiresAt->timestamp, $created->invitation->expires_at->timestamp);
    }

    public function test_issuing_a_replacement_revokes_the_previous_unused_invitation(): void
    {
        $operator = User::factory()->operator()->create();
        $company = Company::factory()->create();
        $action = app(CreateUserInvitation::class);
        $arguments = [
            'inviter' => $operator,
            'company' => $company,
            'email' => 'replacement@example.com',
            'role' => UserRole::CustomerUser,
            'expiresAt' => now()->addDay(),
        ];

        $first = $action->handle(...$arguments);
        $second = $action->handle(...$arguments);

        $this->assertNotNull($first->invitation->fresh()->revoked_at);
        $this->assertNull($second->invitation->fresh()->revoked_at);
        $this->get(route('invitations.accept', $first->token))->assertNotFound();
        $this->get(route('invitations.accept', $second->token))->assertOk();
    }

    public function test_unauthorized_user_cannot_create_an_invitation(): void
    {
        $customer = User::factory()->customerUser()->create();

        $this->expectException(AuthorizationException::class);

        app(CreateUserInvitation::class)->handle(
            inviter: $customer,
            company: $customer->company,
            email: 'new.user@example.com',
            role: UserRole::CustomerUser,
            expiresAt: now()->addDay(),
        );
    }

    public function test_an_invitation_cannot_assign_a_system_role(): void
    {
        $operator = User::factory()->operator()->create();

        $this->expectException(ValidationException::class);

        app(CreateUserInvitation::class)->handle(
            inviter: $operator,
            company: Company::factory()->create(),
            email: 'system.user@example.com',
            role: UserRole::Operator,
            expiresAt: now()->addDay(),
        );
    }

    public function test_an_invitation_requires_an_active_company_and_future_expiration(): void
    {
        $operator = User::factory()->operator()->create();

        try {
            app(CreateUserInvitation::class)->handle(
                inviter: $operator,
                company: Company::factory()->inactive()->create(),
                email: 'new.user@example.com',
                role: UserRole::CustomerUser,
                expiresAt: now()->subMinute(),
            );

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company', $exception->errors());
            $this->assertArrayHasKey('expires_at', $exception->errors());
        }
    }

    public function test_valid_invitation_displays_the_acceptance_screen(): void
    {
        $created = $this->createInvitation();

        $this->get(route('invitations.accept', $created->token))
            ->assertOk()
            ->assertSee($created->invitation->email)
            ->assertSee($created->invitation->company->name);
    }

    public function test_guest_can_accept_a_valid_invitation(): void
    {
        $created = $this->createInvitation(role: UserRole::CustomerAdmin);

        $response = $this->post(route('invitations.accept.store', $created->token), [
            'name' => '홍길동',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', $created->invitation->email)->firstOrFail();

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->company->is($created->invitation->company));
        $this->assertSame(UserRole::CustomerAdmin, $user->role);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($created->invitation->fresh()->accepted_at);
        $this->assertTrue($created->invitation->fresh()->acceptedUser->is($user));
    }

    public function test_expired_revoked_used_and_tampered_tokens_are_rejected(): void
    {
        $expired = $this->createInvitation(expiresAt: now()->subMinute());
        $revoked = $this->createInvitation();
        $revoked->invitation->update(['revoked_at' => now()]);
        $used = $this->createInvitation();
        $used->invitation->update([
            'accepted_at' => now(),
            'accepted_user_id' => User::factory()->customerUser()->create([
                'company_id' => $used->invitation->company_id,
                'email' => $used->invitation->email,
            ])->id,
        ]);

        foreach ([$expired->token, $revoked->token, $used->token, 'tampered-token'] as $token) {
            $this->get(route('invitations.accept', $token))->assertNotFound();
            $this->post(route('invitations.accept.store', $token), [
                'name' => '거부 대상',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertNotFound();
        }
    }

    public function test_invitation_is_rejected_if_the_company_becomes_inactive(): void
    {
        $created = $this->createInvitation();
        $created->invitation->company->update(['status' => 'inactive']);

        $this->get(route('invitations.accept', $created->token))->assertNotFound();
        $this->post(route('invitations.accept.store', $created->token), [
            'name' => '비활성 고객사 사용자',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();
    }

    public function test_an_accepted_invitation_cannot_be_reused(): void
    {
        $created = $this->createInvitation();

        app(AcceptUserInvitation::class)->handle(
            token: $created->token,
            name: '첫 사용자',
            password: 'password',
        );

        $this->expectException(ModelNotFoundException::class);

        app(AcceptUserInvitation::class)->handle(
            token: $created->token,
            name: '두 번째 사용자',
            password: 'password',
        );
    }

    public function test_invitation_acceptance_route_is_rate_limited(): void
    {
        $route = app('router')->getRoutes()->getByName('invitations.accept.store');

        $this->assertNotNull($route);
        $this->assertContains('throttle:invitation-accept', $route->gatherMiddleware());
    }

    private function createInvitation(
        UserRole $role = UserRole::CustomerUser,
        mixed $expiresAt = null,
    ): object {
        $operator = User::factory()->operator()->create();

        if ($expiresAt !== null && $expiresAt->isPast()) {
            $token = 'expired-'.str()->random(48);
            $invitation = UserInvitation::factory()->create([
                'company_id' => Company::factory(),
                'invited_by' => $operator->id,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
            ]);

            return (object) ['invitation' => $invitation, 'token' => $token];
        }

        return app(CreateUserInvitation::class)->handle(
            inviter: $operator,
            company: Company::factory()->create(),
            email: fake()->unique()->safeEmail(),
            role: $role,
            expiresAt: $expiresAt ?? now()->addDay(),
        );
    }
}
