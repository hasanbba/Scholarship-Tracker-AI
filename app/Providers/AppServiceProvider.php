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
        Gate::define('scholarships.verify', fn ($user): bool => $user->hasPermission('scholarships.verify'));
        Gate::define('scholarships.publish', fn ($user): bool => $user->hasPermission('scholarships.publish'));
        Gate::define('scholarships.review.view', fn ($user): bool => $user->hasPermission('scholarships.review.view'));
        Gate::define('scholarships.review.manage', fn ($user): bool => $user->hasPermission('scholarships.review.manage'));
        Gate::define('scholarships.changes.approve', fn ($user): bool => $user->hasPermission('scholarships.changes.approve'));
        Gate::define('scholarships.data-quality.manage', fn ($user): bool => $user->hasPermission('scholarships.data-quality.manage'));
        Gate::define('crawler.workers.manage', fn ($user): bool => $user->hasPermission('crawler.workers.manage'));
        Gate::define('crawler.sources.manage', fn ($user): bool => $user->hasPermission('crawler.sources.manage'));
        Gate::define('crawler.jobs.view', fn ($user): bool => $user->hasPermission('crawler.jobs.view'));
        Gate::define('crawler.jobs.manage', fn ($user): bool => $user->hasPermission('crawler.jobs.manage'));
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
        RateLimiter::for('crawler-activation', fn (Request $request) => Limit::perMinute(5)->by('crawler-activation:'.$request->ip())->response(fn () => response()->json(ApiResponse::failure('Too many activation attempts.'), 429)));
        RateLimiter::for('crawler-worker', fn (Request $request) => Limit::perMinute(120)->by('crawler-worker:'.($request->attributes->get('crawler_worker')?->uuid ?? $request->ip()))->response(fn () => response()->json(ApiResponse::failure('Too many crawler requests.'), 429)));
    }
}
