<?php

namespace App\Providers;

use App\Core\Contracts\EventBus;
use App\Core\Contracts\IdempotencyStore;
use App\Infrastructure\Messaging\LogEventBus;
use App\Infrastructure\Redis\ArrayIdempotencyStore;
use App\Observability\Logging\StructuredLogger;
use App\Observability\Tracing\CorrelationId;
use Illuminate\Support\ServiceProvider;

/**
 * Core / Observability bindings.
 *
 * Register this provider in bootstrap/providers.php.
 * Middleware and health routes must be wired in bootstrap/app.php
 * and routes — see app/Core/WIRING.md.
 *
 * EventBus / IdempotencyStore fallbacks are replaced when
 * InfrastructureServiceProvider is registered.
 */
class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CorrelationId::class);
        $this->app->singleton(StructuredLogger::class);

        if (! $this->app->bound(EventBus::class)) {
            $this->app->singleton(EventBus::class, LogEventBus::class);
        }

        if (! $this->app->bound(IdempotencyStore::class)) {
            $this->app->singleton(IdempotencyStore::class, ArrayIdempotencyStore::class);
        }
    }

    public function boot(): void
    {
        // Intentionally lightweight — no route or middleware registration here.
    }
}
