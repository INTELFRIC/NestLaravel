<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Support\SafeCache;
use NestLaravel\Kafka\Support\Transactions;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use RuntimeException;

class DegradedModeTest extends ReliabilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SafeCache::resetBypass();
    }

    private function breakRedis(): void
    {
        config(['cache.stores.dead' => ['driver' => 'redis', 'connection' => 'nowhere']]);
    }

    public function test_cache_available_serves_from_cache(): void
    {
        $calls = 0;
        $cache = new SafeCache;

        $this->assertSame(42, $cache->remember('k', 60, function () use (&$calls) { $calls++; return 42; }));
        $this->assertSame(42, $cache->remember('k', 60, function () use (&$calls) { $calls++; return 99; }));
        $this->assertSame(1, $calls);
    }

    public function test_cache_unavailable_falls_back_to_the_source_and_never_throws(): void
    {
        $this->breakRedis();
        $cache = new SafeCache('dead', bypassSeconds: 30);
        $calls = 0;

        $value = $cache->remember('k', 60, function () use (&$calls) { $calls++; return 'from-db'; });
        $this->assertSame('from-db', $value, 'request still succeeds when Redis is down');

        // Degraded: further calls skip the dead backend entirely (no repeated timeouts) but stay correct.
        $this->assertSame('from-db', $cache->remember('k', 60, function () use (&$calls) { $calls++; return 'from-db'; }));
        $this->assertSame('default', $cache->get('k', 'default'));
        $this->assertFalse($cache->put('k', 1, 60));
        $this->assertFalse($cache->forget('k'));
        $this->assertSame(2, $calls);
        $this->assertSame(1, Metrics::value('nestlaravel_cache_errors_total', ['operation' => 'remember']));
    }

    public function test_cache_recovers_after_the_bypass_window_and_after_a_redis_restart(): void
    {
        $this->breakRedis();
        $dead = new SafeCache('dead', bypassSeconds: 1);
        $dead->remember('k', 60, fn () => 1);

        // "Redis is back" — after the bypass window the (now healthy) store is used again. A restart lost the data: recompute.
        sleep(2);
        $healthy = new SafeCache;
        $calls = 0;
        $this->assertSame('fresh', $healthy->remember('k', 60, function () use (&$calls) { $calls++; return 'fresh'; }));
        $this->assertSame(1, $calls);
        $this->assertSame('fresh', Cache::get('k'));
    }

    public function test_transactions_state_their_retry_contract(): void
    {
        // Laravel only retries deadlocks for the OUTERMOST transaction; leave the test's wrapper transaction first.
        DB::rollBack();
        $attempts = 0;
        DB::statement('create table if not exists t_retry (id integer)');

        // DB-only body: a deadlock is retried and the effects of failed attempts are rolled back.
        $result = Transactions::idempotent(function () use (&$attempts) {
            $attempts++;
            DB::table('t_retry')->insert(['id' => $attempts]);
            if ($attempts < 3) {
                throw new \Illuminate\Database\QueryException('sqlite', 'update x', [], new RuntimeException('Deadlock found when trying to get lock; try restarting transaction'));
            }

            return 'ok';
        }, attempts: 3);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $attempts);
        $this->assertSame([3], DB::table('t_retry')->pluck('id')->all(), 'only the successful attempt persisted');

        // Body with external side effects: never re-run.
        $ran = 0;
        try {
            Transactions::once(function () use (&$ran) {
                $ran++;
                throw new \Illuminate\Database\QueryException('sqlite', 'update x', [], new RuntimeException('Deadlock found when trying to get lock; try restarting transaction'));
            });
        } catch (\Illuminate\Database\QueryException) {
        }
        $this->assertSame(1, $ran);
        DB::statement('drop table if exists t_retry');
    }

    public function test_statement_timeout_is_applied_when_a_connection_opens(): void
    {
        config(['kafka.database.statement_timeout_ms' => 15000]);
        (new \NestLaravel\Kafka\KafkaServiceProvider($this->app))->boot();

        $statements = [];
        $connection = new class($statements)
        {
            public function __construct(public array &$statements) {}

            public function getName(): string { return 'testing'; }

            public function getDriverName(): string { return 'pgsql'; }

            public function statement(string $sql): void { $this->statements[] = $sql; }
        };

        $this->app['events']->dispatch(new ConnectionEstablished($connection));

        $this->assertContains('SET statement_timeout = 15000', $statements);
    }
}
