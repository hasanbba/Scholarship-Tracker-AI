<?php

namespace App\Http\Middleware;

use App\Models\CrawlerWorkerCredential;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCrawlerWorker
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $plain = $request->bearerToken();
        $credential = $plain ? CrawlerWorkerCredential::query()->with('worker')->where('token_hash', hash('sha256', $plain))->first() : null;
        if (! $credential || ($credential->expires_at && $credential->expires_at->isPast())) {
            return response()->json(ApiResponse::failure('Unauthenticated.'), 401);
        }
        if ($credential->worker->status !== 'active') {
            return response()->json(ApiResponse::failure('Worker is disabled.'), 403);
        }
        if ($credential->revoked_at) {
            return response()->json(ApiResponse::failure('Unauthenticated.'), 401);
        }
        $scopes = $credential->scopes ?? [];
        $legacyResultScope = $scope === 'jobs.results' && in_array('jobs.heartbeat', $scopes, true);
        if (! in_array($scope, $scopes, true) && ! $legacyResultScope) {
            return response()->json(ApiResponse::failure('Worker scope is not permitted.'), 403);
        }
        $credential->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('crawler_worker', $credential->worker);
        $request->attributes->set('crawler_credential', $credential);

        return $next($request);
    }
}
