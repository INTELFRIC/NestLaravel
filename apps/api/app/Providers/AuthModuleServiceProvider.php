<?php

namespace App\Providers;

use App\Core\Support\ApiResponse;
use App\Security\Authorization\Gates\AuthorizationGates;
use App\Security\Authorization\Middleware\EnsurePermission;
use App\Security\RateLimiting\RateLimiterRegistry;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionObject;

class AuthModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiterRegistry::register();
        AuthorizationGates::register();

        // Avoid Route [login] not defined on unauthenticated API calls.
        Authenticate::redirectUsing(function (Request $request): ?string {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/login';
        });

        $this->prependAuthenticationExceptionRenderer();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('permission', EnsurePermission::class);

        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('app/Modules/Auth/Presentation/Routes/api.php'));
    }

    /**
     * Ensure AuthenticationException becomes 401 JSON even when a catch-all
     * Throwable renderer is registered earlier in bootstrap/app.php.
     */
    private function prependAuthenticationExceptionRenderer(): void
    {
        $handler = $this->resolveRenderableHandler();
        $reflection = new ReflectionObject($handler);

        if (! $reflection->hasProperty('renderCallbacks')) {
            return;
        }

        $property = $reflection->getProperty('renderCallbacks');
        $callbacks = $property->getValue($handler);

        array_unshift($callbacks, function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                message: 'Unauthenticated.',
                status: 401,
            );
        });

        $property->setValue($handler, $callbacks);
    }

    private function resolveRenderableHandler(): object
    {
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        // Collision wraps the framework handler in local/testing.
        $reflection = new ReflectionObject($handler);
        if ($reflection->hasProperty('appExceptionHandler')) {
            $property = $reflection->getProperty('appExceptionHandler');

            return $property->getValue($handler);
        }

        return $handler instanceof ExceptionHandler
            ? $handler
            : $this->app->make(ExceptionHandler::class);
    }
}
