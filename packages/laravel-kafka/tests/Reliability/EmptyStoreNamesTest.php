<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use NestLaravel\Kafka\CacheIdempotencyStore;
use NestLaravel\Kafka\Health\HealthChecker;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Resilience\CircuitBreaker;
use NestLaravel\Kafka\Support\SafeCache;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;

/**
 * Regression, found by the clean-install E2E: a generated `.env` contains optional entries such as `METRICS_CACHE_STORE=`
 * (empty). `env()` returns '' for them and Laravel treats '' as a store NAME ("Cache store [] is not defined"), whereas
 * null means "the default". Every optional store/connection name must therefore accept '' as "use the default".
 */
class EmptyStoreNamesTest extends ReliabilityTestCase
{
    public function test_metrics_work_with_an_empty_store_name(): void
    {
        config(['kafka.metrics.store' => '']);

        Metrics::inc('empty_store_total');

        $this->assertSame(1, Metrics::value('empty_store_total'));
        $this->assertSame(0, Metrics::failures());
    }

    public function test_safe_cache_uses_the_default_store_for_an_empty_name(): void
    {
        $cache = new SafeCache('');

        $this->assertTrue($cache->put('k', 'v', 60));
        $this->assertSame('v', $cache->get('k'));
        $this->assertSame('computed', $cache->remember('other', 60, fn () => 'computed'));
    }

    public function test_circuit_breaker_uses_the_default_store_for_an_empty_name(): void
    {
        $breaker = new CircuitBreaker('svc', failureThreshold: 2, windowSeconds: 30, openSeconds: 20, store: '');

        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertSame(CircuitBreaker::OPEN, $breaker->state(), 'the breaker really stored its state (it would fail open on a broken store)');
    }

    public function test_idempotency_store_uses_the_default_store_for_an_empty_name(): void
    {
        $store = new CacheIdempotencyStore('p:', '');

        $store->remember('evt-1', 60);

        $this->assertTrue($store->has('evt-1'));
    }

    public function test_health_check_uses_the_default_connection_for_an_empty_name(): void
    {
        config(['kafka.health.database_connection' => '']);

        $this->assertSame('ok', (new HealthChecker)->check('database')['status']);
    }
}
