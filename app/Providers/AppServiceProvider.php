<?php

namespace App\Providers;

use App\Models\Scholarship;
use App\Policies\ScholarshipPolicy;
use App\Support\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        Gate::define('admin.access', fn ($user): bool => $user->hasPermission('admin.access'));
        Gate::policy(Scholarship::class, ScholarshipPolicy::class);

        RateLimiter::for('auth', function (Request $request) {
            $rateLimitResponse = fn () => response()->json(
                ApiResponse::failure('Too many authentication attempts. Please try again shortly.'),
                429,
            );

            return [
                Limit::perMinute(30)->by('auth-ip:'.$request->ip())->response($rateLimitResponse),
                Limit::perMinute(5)->by('auth-email:'.strtolower((string) $request->input('email')).'|'.$request->ip())->response($rateLimitResponse),
            ];
        });
    }
}
