<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitialAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_initial_verified_super_administrator(): void
    {
        $this->artisan('desk:create-initial-admin')
            ->expectsQuestion('Administrator name', 'Desk Admin')
            ->expectsQuestion('Administrator email', 'ADMIN@EXAMPLE.COM')
            ->expectsQuestion('Password', 'Secure-password-123!')
            ->expectsQuestion('Confirm password', 'Secure-password-123!')
            ->expectsOutput('Initial administrator created.')
            ->assertSuccessful();

        $user = User::query()->sole();

        $this->assertSame('Desk Admin', $user->name);
        $this->assertSame('admin@example.com', $user->email);
        $this->assertSame(UserRole::SuperAdmin, $user->role);
        $this->assertNull($user->company_id);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Secure-password-123!', $user->password));
    }

    public function test_it_refuses_to_create_another_user_after_initial_setup(): void
    {
        User::factory()->superAdmin()->create();

        $this->artisan('desk:create-initial-admin')
            ->expectsOutput('A user already exists. Initial administrator creation is disabled.')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }
}
