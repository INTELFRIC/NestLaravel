<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;

/**
 * Regression: Laravel's DEFAULT cache store is `database`. Metrics live in the cache, and a metric write is then a SQL
 * query, which the query-duration listener measures, which writes a metric… Found by the clean-install E2E (`php artisan
 * migrate` died with a PHP fatal in a freshly created workspace), invisible to every test that used the array cache.
 */
class MetricsOnDatabaseCacheTest extends ReliabilityTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'database');
        $app['config']->set('cache.stores.database.connection', 'testing');
        $app['config']->set('cache.stores.database.lock_connection', 'testing');
        $app['config']->set('kafka.metrics.db_queries', true);   // the listener is registered at boot from this flag
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        // Before ReliabilityTestCase::setUp() resets the metrics (which already touches the cache store).
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function test_query_metrics_on_the_database_cache_store_do_not_recurse(): void
    {
        DB::table('payments')->insert(['order_id' => 'o-1', 'amount' => 1]);
        DB::table('payments')->count();            // reaching this line at all is the regression check

        Metrics::inc('bench_total', ['x' => 'y']);
        $this->assertSame(1, Metrics::value('bench_total', ['x' => 'y']));
        $this->assertSame(0, Metrics::failures());
    }

    public function test_a_histogram_observation_is_three_cache_writes_not_thirteen(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        Metrics::observe('nestlaravel_bench_seconds', 0.003);      // fits the first bucket
        $first = $queries;
        $queries = 0;
        Metrics::observe('nestlaravel_bench_seconds', 0.003);      // series already indexed: pure writes
        $steady = $queries;

        $this->assertLessThanOrEqual(8, $steady, "a steady-state observation issued $steady queries (was ~13 cache writes before)");
        $this->assertGreaterThan(0, $first);
    }

    public function test_exported_histogram_buckets_are_still_cumulative(): void
    {
        Metrics::observe('nestlaravel_bench_seconds', 0.003);     // ≤ 0.005
        Metrics::observe('nestlaravel_bench_seconds', 0.2);       // ≤ 0.25
        Metrics::observe('nestlaravel_bench_seconds', 30);        // beyond every bucket → only +Inf

        $out = Metrics::render();

        $this->assertStringContainsString('nestlaravel_bench_seconds_bucket{le="0.005"} 1', $out);
        $this->assertStringContainsString('nestlaravel_bench_seconds_bucket{le="0.1"} 1', $out);
        $this->assertStringContainsString('nestlaravel_bench_seconds_bucket{le="0.25"} 2', $out);
        $this->assertStringContainsString('nestlaravel_bench_seconds_bucket{le="10"} 2', $out);
        $this->assertStringContainsString('nestlaravel_bench_seconds_bucket{le="+Inf"} 3', $out);
        $this->assertStringContainsString('nestlaravel_bench_seconds_count 3', $out);
    }
}
