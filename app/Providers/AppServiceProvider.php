<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
        Password::defaults(function () {
            $rule = Password::min(8)->mixedCase()->numbers()->symbols();

            // Breach lookup calls an external API, so only enforce it in production.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        $this->configureRateLimiting();

        // Super Admin implicitly holds every permission (including future ones).
        // Inactive accounts never receive this bypass.
        Gate::before(function (User $user) {
            return $user->isActive() && $user->isSuperAdmin() ? true : null;
        });
    }

    private function configureRateLimiting(): void
    {
        // Per email+IP stops targeted guessing; the per-IP cap stops spraying many emails.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('verification', fn (Request $request) => [
            Limit::perMinute(3)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(10)->by($request->ip()),
        ]);
    }
}
