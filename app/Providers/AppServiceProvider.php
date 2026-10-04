<?php

namespace App\Providers;

use App\Contracts\MalwareScanner;
use App\Enums\Permission;
use App\Models\User;
use App\Services\ClamAvMalwareScanner;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, ClamAvMalwareScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureAuthorization(): void
    {
        Gate::before(static fn (User $user): ?bool => $user->canAccessWorkspace() ? null : false);

        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                static fn (User $user): bool => $user->role->hasPermission($permission),
            );
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('attachment-download', function (Request $request): array {
            $user = $request->user();
            $userKey = $user instanceof User ? (string) $user->id : 'guest:'.($request->ip() ?? 'unknown');

            return [
                Limit::perMinute(10)->by('attachment-download:user:'.$userKey),
                Limit::perHour(100)->by('attachment-download:ip:'.($request->ip() ?? 'unknown')),
            ];
        });
    }
}
