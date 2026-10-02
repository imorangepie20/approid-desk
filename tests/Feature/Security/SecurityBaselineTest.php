<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class SecurityBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_include_baseline_security_headers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_password_reset_email_route_is_rate_limited(): void
    {
        $route = RouteFacade::getRoutes()->getByName('password.email');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertContains('throttle:password-reset', $route->gatherMiddleware());
    }

    public function test_password_reset_email_requests_are_rejected_after_three_attempts_per_minute(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('password.email'), ['email' => $user->email])
                ->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertTooManyRequests();
    }

    public function test_session_cookie_keeps_http_only_and_same_site_defaults(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }
}
