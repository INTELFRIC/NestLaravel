<?php

namespace NestLaravel\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NestLaravel\Kafka\KafkaServiceProvider;
use NestLaravel\Tenancy\TenancyServiceProvider;
use NestLaravel\Tenancy\TenantContext;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected TenantContext $tenants;

    protected function getPackageProviders($app): array
    {
        return [TenancyServiceProvider::class, KafkaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->tenantId();
            $table->string('number');
            $table->timestamps();
        });

        $this->tenants = $this->app->make(TenantContext::class);
    }
}
