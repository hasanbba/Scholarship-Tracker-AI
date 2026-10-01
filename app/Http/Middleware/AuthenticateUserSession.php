<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateUserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // User API tokens are not enabled on the User model; only the browser session is supported.
        if ($request->bearerToken() || ! Auth::guard('web')->check()) {
            return response()->json(ApiResponse::failure('Unauthenticated.'), 401);
        }

        Auth::shouldUse('web');

        return $next($request);
    }
}
