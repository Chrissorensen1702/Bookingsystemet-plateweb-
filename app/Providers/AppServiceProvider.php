<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();

            if ((bool) config('security.password.require_uncompromised', false)) {
                $rule = $rule->uncompromised(3);
            }

            return $rule;
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(5)->by($request->ip().'|'.mb_strtolower(trim($email)));
        });

        RateLimiter::for('public-booking-view', function (Request $request): Limit {
            $tenant = mb_strtolower(trim((string) $request->query('tenant', '')));

            return Limit::perMinute(90)->by($request->ip().'|'.$tenant);
        });

        RateLimiter::for('public-booking-time-options', function (Request $request): Limit {
            $tenant = mb_strtolower(trim((string) $request->query('tenant', '')));
            $locationId = (int) $request->query('location_id', 0);

            return Limit::perMinute(120)->by($request->ip().'|'.$tenant.'|'.$locationId);
        });

        RateLimiter::for('public-booking-store', function (Request $request): Limit {
            $tenant = mb_strtolower(trim((string) $request->query('tenant', '')));
            $locationId = (int) $request->input('location_id', 0);
            $email = mb_strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute(12)->by($request->ip().'|'.$tenant.'|'.$locationId.'|'.$email);
        });

        Gate::define('bookings.manage', fn (User $user): bool => $user->hasPermission('bookings.manage'));
        Gate::define('services.manage', fn (User $user): bool => $user->hasPermission('services.manage'));
        Gate::define('availability.manage', fn (User $user): bool => $user->hasPermission('availability.manage'));
        Gate::define('settings.location.manage', fn (User $user): bool => $user->hasPermission('settings.location.manage'));
        Gate::define('settings.global.manage', fn (User $user): bool => $user->hasPermission('settings.global.manage'));
        Gate::define('users.manage', fn (User $user): bool => $user->hasPermission('users.manage'));
        Gate::define('users.permissions.manage', fn (User $user): bool => $user->canManageRolePermissions());

        Gate::define('manage-users', fn (User $user): bool => $user->canManageUsers());
        Gate::define('manage-services', fn (User $user): bool => $user->canManageServices());
        Gate::define('manage-branding', fn (User $user): bool => $user->canManageBranding());
    }
}
