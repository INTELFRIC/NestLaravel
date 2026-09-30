<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\Artisan;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Schema\EventSchema;
use NestLaravel\Kafka\Schema\EventSchemaRegistry;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;

class OpsCommandsTest extends ReliabilityTestCase
{
    /** @return array<string, mixed> */
    private function runJson(string $command, array $args = []): array
    {
        Artisan::call($command, $args + ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_outbox_status_reports_counts_and_can_requeue_failed_rows(): void
    {
        $this->app->make(EventBus::class)->publish(new PaymentCompleted('o-1'));
        $this->app->make(EventBus::class)->publish(new PaymentCompleted('o-2'));
        OutboxMessage::query()->first()->update(['status' => 'failed', 'attempts' => 5, 'last_error' => 'broker unavailable']);

        $report = $this->runJson('outbox:status', ['--failed' => true]);
        $this->assertSame(1, $report['status']['pending']);
        $this->assertSame(1, $report['status']['failed']);
        $this->assertSame('broker unavailable', $report['failed'][0]['last_error']);

        Artisan::call('outbox:status', ['--requeue' => true]);
        $this->assertSame(0, OutboxMessage::where('status', 'failed')->count());
        $this->assertSame(2, OutboxMessage::where('status', 'pending')->count());
    }

    public function test_events_list_and_compatibility_gate(): void
    {
        $registry = $this->app->make(EventSchemaRegistry::class);
        $registry->register(new EventSchema('orders.order.created', 1, ['order_id' => 'required|string']));
        $registry->register(new EventSchema('orders.order.created', 2, ['order_id' => 'required|string', 'currency' => 'nullable|string']));

        $rows = $this->runJson('events:list');
        $this->assertSame([1, 2], array_column($rows, 'version'));
        $this->assertSame(0, Artisan::call('events:check'));

        $registry->register(new EventSchema('orders.order.created', 3, ['order_id' => 'required|string', 'currency' => 'required|string']));
        $this->assertSame(1, Artisan::call('events:check'), 'a breaking schema change fails the CI gate');
        $this->assertStringContainsString('currency', Artisan::output());
    }

    public function test_kafka_health_reports_settings_without_secrets(): void
    {
        config(['kafka.enabled' => false, 'kafka.security.sasl_password' => 'super-secret-pw']);

        Artisan::call('kafka:health', ['--json' => true]);
        $out = Artisan::output();

        $this->assertStringNotContainsString('super-secret-pw', $out);
        $this->assertFalse(json_decode($out, true)['enabled']);
    }

    public function test_inbox_prune_command(): void
    {
        \Illuminate\Support\Facades\DB::table('inbox_events')->insert(['consumer' => 'c', 'event_id' => 'old', 'processed_at' => now()->subDays(40)]);
        \Illuminate\Support\Facades\DB::table('inbox_events')->insert(['consumer' => 'c', 'event_id' => 'new', 'processed_at' => now()]);

        Artisan::call('inbox:prune', ['--days' => 14]);

        $this->assertSame(['new'], \Illuminate\Support\Facades\DB::table('inbox_events')->pluck('event_id')->all());
    }

    public function test_dlq_list_reads_the_dead_letter_file_without_consuming_it(): void
    {
        $dir = sys_get_temp_dir().'/nl-dlq-'.bin2hex(random_bytes(3));
        mkdir($dir);
        config(['kafka.consumer_pipeline.log_path' => $dir, 'kafka.topics.default' => 'orders.events']);
        file_put_contents($dir.'/orders.events.dlq.jsonl', json_encode(['key' => 'o-1', 'value' => '{}', 'headers' => ['dlq_reason' => 'boom', 'original_topic' => 'orders.events']])."\n");

        $first = $this->runJson('dlq:list');
        $second = $this->runJson('dlq:list');

        $this->assertSame('orders.events.dlq', $first['topic']);
        $this->assertSame('boom', $first['messages'][0]['reason']);
        $this->assertSame($first, $second, 'listing is read-only and repeatable');
    }

    public function test_production_check_reports_findings_and_fails_on_insecure_production_config(): void
    {
        config(['app.env' => 'production', 'app.debug' => true, 'kafka.enabled' => true, 'kafka.security.protocol' => 'plaintext', 'kafka.brokers' => '127.0.0.1:1', 'cache.default' => 'array']);

        $report = $this->runJson('nestlaravel:check');
        $byId = array_column($report['findings'], null, 'id');

        $this->assertSame('fail', $byId['debug']['status']);
        $this->assertSame('fail', $byId['kafka_tls']['status']);
        $this->assertSame('warn', $byId['shared_cache']['status']);
        $this->assertSame('warn', $byId['backups']['status'], 'the framework never claims it verified backups');
        $this->assertSame(1, Artisan::call('nestlaravel:check'));
    }

    public function test_production_check_reports_an_unreachable_database_instead_of_crashing(): void
    {
        $original = config('database.default');
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/nl-does-not-exist/none.sqlite', 'prefix' => ''],
            'database.default' => 'broken',
        ]);

        try {
            \Illuminate\Support\Facades\DB::purge('broken');
            $code = Artisan::call('nestlaravel:check', ['--json' => true]);
            $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            config(['database.default' => $original]);
        }

        $byId = array_column($report['findings'], null, 'id');
        $this->assertSame(1, $code, 'exit code signals the failure');
        $this->assertSame('fail', $byId['database']['status']);
        $this->assertArrayHasKey('app_key', $byId, 'sections before the failure still ran');
        $this->assertNotEmpty(array_filter($report['findings'], fn ($f) => $f['id'] === 'backups'), 'later sections still reported');
    }

    public function test_production_check_warns_when_metrics_live_in_a_sql_or_file_cache(): void
    {
        config(['cache.default' => 'database', 'cache.stores.database.driver' => 'database', 'kafka.metrics.store' => null]);
        $byId = array_column($this->runJson('nestlaravel:check')['findings'], null, 'id');
        $this->assertSame('warn', $byId['metrics']['status']);
        $this->assertStringContainsString('SQL query', $byId['metrics']['message']);

        config(['cache.stores.redis-metrics' => ['driver' => 'redis'], 'kafka.metrics.store' => 'redis-metrics']);
        $byId = array_column($this->runJson('nestlaravel:check')['findings'], null, 'id');
        $this->assertSame('pass', $byId['metrics']['status']);
    }

    public function test_production_check_passes_a_hardened_configuration_but_still_reports_warnings(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'kafka.enabled' => false, 'kafka.inbox.enabled' => true, 'kafka.schema.enforce_producer' => true,
            'kafka.database.statement_timeout_ms' => 15000, 'kafka.metrics.token' => 'x',
            'internal.secret' => str_repeat('a', 64), 'internal.replay_protection_required' => true,
            'logging.default' => 'nestlaravel', 'cache.default' => 'database',
        ]);

        $report = $this->runJson('nestlaravel:check');
        $statuses = array_count_values(array_column($report['findings'], 'status'));

        // The only failure is the test database itself: SQLite is (rightly) rejected for production.
        $fails = array_column(array_filter($report['findings'], fn ($f) => $f['status'] === 'fail'), 'id');
        $this->assertSame(['database_engine'], $fails);
        $this->assertGreaterThan(0, $statuses['warn'] ?? 0, 'tracing / backups / kafka acls are never claimed as verified');
    }
}
