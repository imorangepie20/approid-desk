<?php

namespace Tests\Feature\Models;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_user_belongs_to_exactly_one_company(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::CustomerUser, $user->role);
        $this->assertInstanceOf(Company::class, $user->company);
        $this->assertTrue($user->role->isCustomerRole());
    }

    public function test_system_users_do_not_belong_to_a_customer_company(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $operator = User::factory()->operator()->create();

        $this->assertSame(UserRole::SuperAdmin, $superAdmin->role);
        $this->assertNull($superAdmin->company_id);
        $this->assertSame(UserRole::Operator, $operator->role);
        $this->assertNull($operator->company_id);
    }

    public function test_customer_role_without_a_company_is_rejected_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->userAttributes([
            'company_id' => null,
            'role' => UserRole::CustomerUser->value,
        ]));
    }

    public function test_system_role_with_a_customer_company_is_rejected_by_the_database(): void
    {
        $company = Company::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->userAttributes([
            'company_id' => $company->id,
            'role' => UserRole::Operator->value,
        ]));
    }

    public function test_inactive_factory_state_marks_the_user_inactive(): void
    {
        $user = User::factory()->inactive()->create();

        $this->assertFalse($user->is_active);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userAttributes(array $overrides): array
    {
        return [
            'name' => 'Constraint Test',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }
}
