<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_filter_the_company_directory(): void
    {
        $operator = User::factory()->operator()->create();
        Company::factory()->create(['name' => '검색 활성 고객사']);
        Company::factory()->inactive()->create(['name' => '검색 비활성 고객사']);
        Company::factory()->create(['name' => '표시 제외 고객사']);

        $this->actingAs($operator)
            ->get(route('companies.index', ['search' => '검색', 'status' => CompanyStatus::Active->value]))
            ->assertOk()
            ->assertSee('검색 활성 고객사')
            ->assertDontSee('검색 비활성 고객사')
            ->assertDontSee('표시 제외 고객사');
    }

    public function test_operator_can_create_and_update_a_company(): void
    {
        $operator = User::factory()->operator()->create();

        $createResponse = $this->actingAs($operator)->post(route('companies.store'), [
            'name' => '새 고객사',
            'status' => CompanyStatus::Active->value,
            'primary_contact_name' => '김담당',
            'primary_contact_email' => 'contact@example.com',
        ]);

        $company = Company::query()->where('name', '새 고객사')->firstOrFail();
        $createResponse->assertRedirect(route('companies.show', $company));

        $this->actingAs($operator)
            ->patch(route('companies.update', $company), [
                'name' => '수정 고객사',
                'status' => CompanyStatus::Inactive->value,
                'primary_contact_name' => '이담당',
                'primary_contact_email' => 'updated@example.com',
                'primary_contact_phone' => '02-1234-5678',
                'notes' => '운영 메모',
            ])
            ->assertRedirect(route('companies.show', $company));

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => '수정 고객사',
            'status' => CompanyStatus::Inactive->value,
            'primary_contact_email' => 'updated@example.com',
        ]);
    }

    public function test_customer_admin_sees_only_their_company_users_and_invitations(): void
    {
        $company = Company::factory()->create(['name' => '자사 고객사']);
        $otherCompany = Company::factory()->create(['name' => '타사 고객사']);
        $admin = User::factory()->customerAdmin()->for($company)->create();
        User::factory()->customerUser()->for($company)->create([
            'name' => '자사 사용자',
            'email' => 'own@example.com',
        ]);
        User::factory()->customerUser()->for($otherCompany)->create([
            'name' => '타사 사용자',
            'email' => 'other@example.com',
        ]);
        UserInvitation::factory()->for($company)->create([
            'invited_by' => $admin->id,
            'email' => 'own-invite@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('companies.show', $company))
            ->assertOk()
            ->assertSee('자사 사용자')
            ->assertSee('own-invite@example.com')
            ->assertDontSee('타사 사용자')
            ->assertDontSee('고객사 정보 저장');

        $this->actingAs($admin)
            ->get(route('companies.show', $otherCompany))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('companies.index'))
            ->assertForbidden();
    }

    public function test_customer_user_cannot_open_company_management_pages(): void
    {
        $user = User::factory()->customerUser()->create();

        $this->actingAs($user)
            ->get(route('companies.show', $user->company_id))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('companies.index'))
            ->assertForbidden();
    }

    public function test_authorized_manager_can_create_an_invitation_from_the_screen(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->customerAdmin()->for($company)->create();

        $response = $this->actingAs($admin)->post(route('companies.invitations.store', $company), [
            'email' => ' New.User@Example.com ',
            'role' => UserRole::CustomerUser->value,
            'expires_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]);

        $invitation = UserInvitation::query()->where('email', 'new.user@example.com')->firstOrFail();

        $response
            ->assertRedirect(route('companies.show', $company))
            ->assertSessionHas('invitation_url', function (string $url) use ($invitation): bool {
                $token = basename($url);

                return $token !== ''
                    && hash('sha256', $token) === $invitation->token_hash;
            });

        $this->assertSame($company->id, $invitation->company_id);
        $this->assertSame($admin->id, $invitation->invited_by);
        $this->assertSame(UserRole::CustomerUser, $invitation->role);
    }

    public function test_invitation_screen_rejects_registered_email_and_inactive_company(): void
    {
        $operator = User::factory()->operator()->create();
        $registeredUser = User::factory()->create(['email' => 'registered@example.com']);
        $activeCompany = Company::factory()->create();
        $inactiveCompany = Company::factory()->inactive()->create();
        $payload = [
            'email' => $registeredUser->email,
            'role' => UserRole::CustomerAdmin->value,
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ];

        $this->actingAs($operator)
            ->from(route('companies.show', $activeCompany))
            ->post(route('companies.invitations.store', $activeCompany), $payload)
            ->assertSessionHasErrors('email');

        $this->actingAs($operator)
            ->from(route('companies.show', $inactiveCompany))
            ->post(route('companies.invitations.store', $inactiveCompany), [
                ...$payload,
                'email' => 'inactive-company@example.com',
            ])
            ->assertSessionHasErrors('company');
    }

    public function test_manager_can_revoke_a_pending_invitation_but_not_one_from_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->customerAdmin()->for($company)->create();
        $invitation = UserInvitation::factory()->for($company)->create(['invited_by' => $admin->id]);
        $otherInvitation = UserInvitation::factory()->for($otherCompany)->create();

        $this->actingAs($admin)
            ->delete(route('companies.invitations.destroy', [$company, $invitation]))
            ->assertRedirect(route('companies.show', $company));

        $this->assertNotNull($invitation->fresh()->revoked_at);

        $this->actingAs($admin)
            ->delete(route('companies.invitations.destroy', [$company, $otherInvitation]))
            ->assertNotFound();
    }

    public function test_users_from_an_inactive_company_cannot_access_work_pages(): void
    {
        $company = Company::factory()->inactive()->create();
        $admin = User::factory()->customerAdmin()->for($company)->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('companies.show', $company))
            ->assertForbidden();
    }
}
