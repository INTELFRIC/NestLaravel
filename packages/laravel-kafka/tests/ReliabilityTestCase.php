<?php

namespace NestLaravel\Kafka\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Observability\Tracer;

/**
 * Base for reliability tests: migrated in-memory DB (outbox + inbox), a `payments` table standing in for a
 * business table, and clean ambient state (metrics, log/trace context) for every test.
 */
abstract class ReliabilityTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->index();
            $table->unsignedInteger('amount');
        });

        config([
            'kafka.metrics.enabled' => true,
            'kafka.consumer_pipeline.retry_backoff_ms' => 0,
            'kafka.consumer_pipeline.max_retries' => 2,
        ]);

        Metrics::reset();
        LogContext::clear();
        Tracer::reset();
    }
}
