<?php

namespace App\Providers;

use App\Infrastructure\Gateway\GatewayProxy;
use App\Infrastructure\Gateway\GatewayProxyController;
use App\Infrastructure\Gateway\ServiceRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers apps/api as the platform API Gateway.
 *
 * Local modules keep their own routes. Enabled remote services are
 * exposed under /api/v1/{prefix}/{path} and proxied downstream.
 */
class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServiceRegistry::class);
        $this->app->singleton(GatewayProxy::class);
    }

    public function boot(): void
    {
        if (! config('gateway.enabled', true)) {
            return;
        }

        /** @var ServiceRegistry $registry */
        $registry = $this->app->make(ServiceRegistry::class);

        foreach ($registry->enabled() as $service) {
            // Gateway is the PUBLIC boundary: authenticate + throttle before proxying.
            $middleware = $service->public ? ['api'] : ['api', 'auth:sanctum'];
            $middleware[] = 'throttle:api';

            Route::any(
                'api/v1/'.$service->prefix.'/{path?}',
                GatewayProxyController::class,
            )
                ->where('path', '.*')
                ->defaults('service', $service->name)
                ->middleware($middleware)
                ->name('gateway.'.$service->name);
        }

        Route::get('api/gateway/services', function (ServiceRegistry $registry) {
            return response()->json([
                'success' => true,
                'data' => collect($registry->all())->map(fn ($s) => [
                    'name' => $s->name,
                    'prefix' => $s->prefix,
                    'enabled' => $s->enabled,
                ])->values(),
                'message' => 'API gateway service registry',
            ]);
        })->middleware(['api', 'auth:sanctum', 'throttle:api'])->name('gateway.services');
    }
}
