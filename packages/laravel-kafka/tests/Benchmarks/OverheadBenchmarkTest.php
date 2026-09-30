<?php

namespace NestLaravel\Kafka\Tests\Benchmarks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Monolog\Level;
use Monolog\LogRecord;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Consumers\MessageHandler;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Contracts\HasEventSchema;
use NestLaravel\Kafka\Events\AbstractDomainEvent;
use NestLaravel\Kafka\Http\Middleware\ObserveRequest;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\Observability\JsonLogFormatter;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Observability\Redactor;
use NestLaravel\Kafka\Observability\TraceContext;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Outbox\OutboxPublisher;
use NestLaravel\Kafka\Schema\EventSchema;
use NestLaravel\Kafka\Schema\EventSchemaRegistry;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use NestLaravel\Kafka\Tests\Support\FakeProducer;

final class BenchOrderCreated extends AbstractDomainEvent implements HasEventSchema
{
    public function __construct(private readonly string $id)
    {
        parent::__construct();
    }

    public static function eventSchema(): EventSchema
    {
        return new EventSchema('bench.order.created', 1, ['id' => 'required|string', 'total' => 'required|numeric|min:0', 'currency' => 'nullable|string']);
    }

    public function eventType(): string { return 'bench.order.created'; }

    public function aggregateId(): string { return $this->id; }

    public function aggregateType(): string { return 'order'; }

    public function payload(): array { return ['id' => $this->id, 'total' => 42.5, 'currency' => 'EUR']; }
}

final class BenchInsertHandler implements MessageHandler
{
    public function handle(array $event): void
    {
        DB::table('payments')->insert(['order_id' => $event['payload']['order_id'], 'amount' => 1]);
    }
}

/**
 * Measures what the reliability features cost. Opt-in (NL_BENCH=1), never part of the normal run:
 *
 *   NL_BENCH=1 php vendor/bin/phpunit --filter OverheadBenchmarkTest
 *
 * Numbers are for the machine they ran on (in-memory SQLite, array cache, fake producer): they show the RELATIVE cost of a
 * feature (with vs without), not what your PostgreSQL/Redis/Kafka deployment will do. Results are written to
 * benchmarks/results/latest.json.
 */
class OverheadBenchmarkTest extends ReliabilityTestCase
{
    /** @var array<string, array<string, mixed>> */
    private static array $results = [];

    protected function setUp(): void
    {
        if (! getenv('NL_BENCH')) {
            $this->markTestSkipped('Benchmarks are opt-in: NL_BENCH=1');
        }

        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$results === []) {
            return;
        }

        $dir = __DIR__.'/../../benchmarks/results';
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/latest.json', json_encode([
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'sqlite' => \SQLite3::version()['versionString'] ?? 'n/a',
            'date' => gmdate('c'),
            'results' => self::$results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Best of $rounds runs (least noise from other processes). Returns ops/second and microseconds per operation.
     *
     * @return array{ops_per_sec: float, us_per_op: float, n: int}
     */
    private function measure(string $name, int $n, callable $setup, callable $op, int $rounds = 5): array
    {
        $best = INF;
        for ($r = 0; $r < $rounds; $r++) {
            $setup();
            gc_collect_cycles();
            $t = hrtime(true);
            for ($i = 0; $i < $n; $i++) {
                $op($i);
            }
            $best = min($best, (hrtime(true) - $t) / 1e9);
        }

        $row = ['ops_per_sec' => round($n / $best, 1), 'us_per_op' => round($best / $n * 1e6, 2), 'n' => $n];
        self::$results[$name] = $row;
        fwrite(STDERR, sprintf("  %-46s %10.1f ops/s  %9.2f µs/op\n", $name, $row['ops_per_sec'], $row['us_per_op']));
        $this->addToAssertionCount(1);

        return $row;
    }

    private function message(int $i): KafkaMessage
    {
        return new KafkaMessage('domain.events', "k$i", json_encode([
            'event_id' => "evt-$i-".uniqid(), 'event_type' => 'orders.order.created', 'event_version' => 1,
            'aggregate_id' => "o$i", 'aggregate_type' => 'order', 'payload' => ['order_id' => "o$i"],
        ]), partition: 0, offset: $i);
    }

    private function pipeline(bool $inbox): ConsumerPipeline
    {
        config(['kafka.inbox.enabled' => $inbox, 'kafka.group_id' => 'bench']);
        $this->app->instance(KafkaProducer::class, new FakeProducer);
        $this->app->forgetInstance(ConsumerPipeline::class);

        return $this->app->make(ConsumerPipeline::class);
    }

    public function test_consumer_idempotency_cache_vs_transactional_inbox(): void
    {
        fwrite(STDERR, "\nConsumer: one handler DB insert per message\n");
        $handler = new BenchInsertHandler;
        $messages = array_map(fn ($i) => $this->message($i), range(0, 1999));
        $reset = function () {
            DB::table('payments')->delete();
            DB::table('inbox_events')->delete();
            \Illuminate\Support\Facades\Cache::flush();
        };

        $cache = $this->pipeline(false);
        $a = $this->measure('consumer.cache_idempotency', 2000, function () use ($reset, $messages) {
            $reset();
            foreach ($messages as $k => $m) {   // fresh event ids each round so nothing is a duplicate
                $messages[$k] = $this->message($k);
            }
        }, fn ($i) => $cache->process($messages[$i], $handler));

        $inbox = $this->pipeline(true);
        $b = $this->measure('consumer.transactional_inbox', 2000, function () use ($reset, &$messages) {
            $reset();
            foreach ($messages as $k => $m) {
                $messages[$k] = $this->message($k);
            }
        }, fn ($i) => $inbox->process($messages[$i], $handler));

        self::$results['consumer.inbox_overhead_us_per_msg'] = ['value' => round($b['us_per_op'] - $a['us_per_op'], 2)];
        fwrite(STDERR, sprintf("  → inbox vs cache overhead: %+.2f µs/message\n", $b['us_per_op'] - $a['us_per_op']));
    }

    public function test_duplicate_detection_cost(): void
    {
        fwrite(STDERR, "\nDuplicate delivery (already processed event)\n");
        $handler = new BenchInsertHandler;
        $pipeline = $this->pipeline(true);
        $m = $this->message(1);
        $pipeline->process($m, $handler);

        // The skip is logged at info level (once as pipeline detail, once by the inbox). Measure the logic alone (null log
        // channel) and with the default file channel, which is what dominates: a duplicate is cheap, writing a log line is not.
        config(['logging.default' => 'null']);
        $this->measure('consumer.inbox_duplicate_skipped_no_log_io', 2000, fn () => null, fn () => $pipeline->process($m, $handler));

        $inbox = $this->app->make(\NestLaravel\Kafka\Inbox\EventInbox::class);
        $inbox->process('direct-1', fn () => null);
        $this->measure('inbox.process_duplicate_direct_no_log_io', 2000, fn () => null, fn () => $inbox->process('direct-1', fn () => null));
        $this->measure('inbox.process_new_event_direct_no_log_io', 2000, fn () => DB::table('inbox_events')->where('event_id', 'like', 'n-%')->delete(), fn ($i) => $inbox->process("n-$i", fn () => null));

        config(['logging.default' => 'single']);
        $this->measure('consumer.inbox_duplicate_skipped_with_file_log', 500, fn () => null, fn () => $pipeline->process($m, $handler), rounds: 3);
        $this->assertSame(1, DB::table('payments')->count(), 'and it really was skipped every time');
    }

    public function test_event_schema_validation_on_publish(): void
    {
        fwrite(STDERR, "\nPublish (outbox insert) with and without schema enforcement\n");
        $run = function (bool $enforce): array {
            // "off" = no schema registered at all; "on" = schema registered and enforced.
            config(['kafka.events' => $enforce ? [BenchOrderCreated::class] : [], 'kafka.schema.enforce_producer' => $enforce]);
            $this->app->forgetInstance(EventSchemaRegistry::class);
            $this->app->forgetInstance(EventBus::class);
            $bus = $this->app->make(EventBus::class);

            return $this->measure($enforce ? 'publish.schema_enforced' : 'publish.no_schema', 2000, fn () => OutboxMessage::query()->delete(), fn ($i) => $bus->publish(new BenchOrderCreated("o$i")));
        };

        $off = $run(false);
        $on = $run(true);
        self::$results['publish.schema_overhead_us_per_event'] = ['value' => round($on['us_per_op'] - $off['us_per_op'], 2)];
        fwrite(STDERR, sprintf("  → schema validation overhead: %+.2f µs/event\n", $on['us_per_op'] - $off['us_per_op']));
    }

    public function test_outbox_publisher_throughput(): void
    {
        fwrite(STDERR, "
Outbox publisher drain (fake producer: measures claim + bookkeeping, not the network)
");
        config(['kafka.events' => [], 'kafka.schema.enforce_producer' => false]);
        $this->app->forgetInstance(EventSchemaRegistry::class);
        $this->app->forgetInstance(EventBus::class);
        $bus = $this->app->make(EventBus::class);

        foreach ([100, 500] as $batch) {
            $this->app->instance(KafkaProducer::class, new FakeProducer);
            $this->app->forgetInstance(OutboxPublisher::class);
            config(['kafka.outbox.batch_size' => $batch]);
            $publisher = $this->app->make(OutboxPublisher::class);

            $best = INF;
            for ($round = 0; $round < 3; $round++) {
                OutboxMessage::query()->delete();
                for ($i = 0; $i < 2000; $i++) {
                    $bus->publish(new BenchOrderCreated("o$i"));
                }
                $t = hrtime(true);
                $published = 0;
                while (($n = $publisher->publishPending($batch)) > 0) {
                    $published += $n;
                }
                $best = min($best, (hrtime(true) - $t) / 1e9);
                $this->assertSame(2000, $published);
            }

            self::$results["outbox.drain_rows_per_sec_batch_$batch"] = ['value' => round(2000 / $best, 1), 'rows' => 2000];
            fwrite(STDERR, sprintf("  %-46s %10.1f rows/s
", "outbox.drain_rows_per_sec_batch_$batch", 2000 / $best));
        }
    }

    public function test_observability_primitives(): void
    {
        fwrite(STDERR, "\nObservability primitives (per call)\n");
        LogContext::set(['request_id' => 'r-1', 'correlation_id' => 'c-1', 'tenant_id' => 'acme']);
        $context = ['order_id' => 'o-1', 'password' => 'x', 'nested' => ['token' => 'abc', 'items' => range(1, 10)], 'note' => 'Bearer abcdefghijklmnop'];

        $this->measure('obs.metrics_inc', 20000, fn () => null, fn () => Metrics::inc('bench_total', ['result' => 'ok']));
        config(['kafka.metrics.enabled' => false]);
        $this->measure('obs.metrics_inc_disabled', 20000, fn () => null, fn () => Metrics::inc('bench_total', ['result' => 'ok']));
        config(['kafka.metrics.enabled' => true]);
        $this->measure('obs.redact_context', 20000, fn () => null, fn () => Redactor::redact($context));

        $formatter = new JsonLogFormatter;
        $record = new LogRecord(new \DateTimeImmutable, 'app', Level::Info, 'Order processed', $context);
        $this->measure('obs.json_log_line', 20000, fn () => null, fn () => $formatter->format($record));

        $this->measure('obs.traceparent_continue_and_child', 20000, fn () => null, fn () => TraceContext::continueFrom('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')->child()->traceparent());
    }

    public function test_observability_middleware_overhead(): void
    {
        fwrite(STDERR, "
HTTP observability middleware (request id, correlation, trace context, RED metrics) vs the bare handler
");
        $middleware = $this->app->make(ObserveRequest::class);
        $next = fn ($request) => response('ok');

        $bare = $this->measure('http.handler_only', 5000, fn () => null, fn () => $next(Request::create('/orders', 'GET')));
        $with = $this->measure('http.handler_with_observe_middleware', 5000, fn () => null, function () use ($middleware, $next) {
            $request = Request::create('/orders', 'GET');
            $response = $middleware->handle($request, $next);
            $middleware->terminate($request, $response);
        });

        self::$results['http.observability_overhead_us_per_request'] = ['value' => round($with['us_per_op'] - $bare['us_per_op'], 2)];
        fwrite(STDERR, sprintf("  → middleware overhead: %+.2f µs/request
", $with['us_per_op'] - $bare['us_per_op']));
    }
}
