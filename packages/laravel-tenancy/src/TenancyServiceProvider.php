<?php

namespace NestLaravel\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenancy.php', 'tenancy');
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/tenancy.php' => config_path('tenancy.php')], 'nestlaravel-tenancy-config');

        // $table->tenantId() in migrations: indexed, non-null tenant column.
        Blueprint::macro('tenantId', function (): void {
            /** @var Blueprint $this */
            $this->string(config('tenancy.column', 'tenant_id'), 64)->index();
        });

        // Queued jobs remember the tenant that dispatched them...
        Queue::createPayloadUsing(function (): array {
            $tenant = app(TenantContext::class)->id();

            return $tenant !== null ? ['tenant_id' => $tenant] : [];
        });

        // ...and run inside it. Jobs without one run with no tenant (scoped queries then throw).
        // The previous tenant is restored afterwards (matters for the sync driver).
        $previous = [];

        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$previous): void {
            $context = app(TenantContext::class);
            $previous[] = $context->id();
            $tenant = $event->job->payload()['tenant_id'] ?? null;
            $context->set(is_string($tenant) ? $tenant : null);
        });

        foreach ([JobProcessed::class, JobFailed::class] as $done) {
            Event::listen($done, function () use (&$previous): void {
                app(TenantContext::class)->set(array_pop($previous));
            });
        }
    }
}
