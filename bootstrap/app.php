<?php

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(ApiResponse::failure('Validation failed.', $exception->errors()), 422);
            }
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(ApiResponse::failure('Unauthenticated.'), 401);
            }
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(ApiResponse::failure('This action is unauthorized.'), 403);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if ($request->is('api/*') && in_array($exception->getStatusCode(), [401, 403, 404, 409, 429], true)) {
                $message = match ($exception->getStatusCode()) {
                    401 => 'Unauthenticated.',
                    403 => 'This action is unauthorized.',
                    404 => 'Resource not found.',
                    409 => 'The request conflicts with the current resource state.',
                    429 => 'Too many requests.',
                };

                return response()->json(ApiResponse::failure($message), $exception->getStatusCode());
            }
        });
    })->create();
