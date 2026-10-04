<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HudShellTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{UserRole}> */
    public static function roles(): array
    {
        return array_combine(array_column(UserRole::cases(), 'value'), array_map(fn ($role) => [$role], UserRole::cases()));
    }

    #[DataProvider('roles')]
    public function test_shared_shell_contains_header_sidebar_and_functional_controls(UserRole $role): void
    {
        $factory = $role->isSystemRole() ? User::factory()->operator() : User::factory();
        $user = $factory->create(['role' => $role]);
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('data-test="desk-header"', false)->assertSee('data-test="desk-sidebar"', false)
            ->assertSee('data-test="desk-theme-toggle"', false)->assertSee('data-test="header-user-menu"', false)
            ->assertSee('data-test="logout-button"', false)->assertSee('id="desk-main"', false)
            ->assertDontSee('laravel/livewire-starter-kit')->assertDontSee('admin@hudadmin.com');
        if ($role->isSystemRole()) {
            $response->assertSee('고객사 관리');
        } else {
            $response->assertDontSee('고객사 관리');
        }
    }
}
