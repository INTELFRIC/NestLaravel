<?php

use App\Core\Exceptions\DomainException;
use App\Core\Support\ApiResponse;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\SecureHeaders;
use App\Infrastructure\Gateway\GatewayUnavailableException;
use App\Security\Authorization\Middleware\EnsurePermission;
use Illuminate\Auth\Access\AuthorizationException as LaravelAuthorizationException;
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
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/health.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            AssignCorrelationId::class,
            SecureHeaders::class,
        ]);

        $middleware->alias([
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (DomainException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $response = ApiResponse::error(
                message: $e->getMessage(),
                errors: $e->context() ?: null,
                status: $e->statusCode(),
                meta: ['error_code' => $e->errorCode()],
            );

            if ($e instanceof GatewayUnavailableException && $e->retryAfter !== null) {
                $response->headers->set('Retry-After', (string) $e->retryAfter);
            }

            return $response;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Unauthenticated.',
                status: 401,
            );
        });

        $exceptions->render(function (LaravelAuthorizationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized.',
                status: 403,
            );
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage(),
                errors: $e->errors(),
                status: $e->status,
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof DomainException
                || $e instanceof AuthenticationException
                || $e instanceof LaravelAuthorizationException
                || $e instanceof ValidationException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = $status >= 500 && ! config('app.debug')
                ? 'Server Error'
                : $e->getMessage();

            return ApiResponse::error(
                message: $message !== '' ? $message : 'Server Error',
                status: $status,
            );
        });
    })->create();
