<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use NestLaravel\Kafka\Observability\ContextProcessor;
use NestLaravel\Kafka\Observability\JsonLogFormatter;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Observability\Redactor;
use NestLaravel\Kafka\Observability\TraceContext;
use NestLaravel\Kafka\Observability\Tracer;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;

class ObservabilityTest extends ReliabilityTestCase
{
    /** @return array{Logger, resource} */
    private function logger(): array
    {
        $stream = fopen('php://memory', 'w+');
        $handler = (new StreamHandler($stream))->setFormatter(new JsonLogFormatter);

        return [(new Logger('t'))->pushHandler($handler)->pushProcessor(new ContextProcessor), $stream];
    }

    private function lastLine($stream): array
    {
        rewind($stream);

        return json_decode(trim((string) stream_get_contents($stream)), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_log_lines_are_json_with_ambient_ids_and_service_metadata(): void
    {
        config(['app.name' => 'payments-service', 'app.env' => 'production']);
        $this->app->instance('correlation_id', 'corr-9');
        $this->app->instance('request_id', 'req-9');
        $this->app->instance('tenant_id', 'acme');
        LogContext::set(['event_id' => 'evt-9', 'causation_id' => 'evt-8', 'trace_id' => str_repeat('a', 32)]);

        [$log, $stream] = $this->logger();
        $log->error('charge failed', ['order' => 'o-1', 'exception' => new \RuntimeException('gateway timeout')]);
        $line = $this->lastLine($stream);

        $this->assertSame('error', $line['level']);
        $this->assertSame('payments-service', $line['service']);
        $this->assertSame('production', $line['environment']);
        foreach (['request_id' => 'req-9', 'correlation_id' => 'corr-9', 'causation_id' => 'evt-8', 'event_id' => 'evt-9', 'tenant_id' => 'acme'] as $k => $v) {
            $this->assertSame($v, $line[$k], $k);
        }
        $this->assertSame(str_repeat('a', 32), $line['trace_id']);
        $this->assertSame('RuntimeException', $line['exception']['class']);
        $this->assertArrayHasKey('timestamp', $line);
    }

    public function test_secrets_never_reach_the_log_output(): void
    {
        [$log, $stream] = $this->logger();
        $log->warning('call failed with Bearer abcdefghijklmnop123456 to https://user:hunter2pass@svc.internal/x', [
            'password' => 'p4ss', 'nested' => ['api_key' => 'k', 'Authorization' => 'Bearer zzzzzzzzzzzz', 'ok' => 'visible'],
            'sasl_password' => 'kafka-secret', 'X-Gateway-Signature' => 'deadbeef',
        ]);
        $raw = (function () use ($stream) { rewind($stream); return stream_get_contents($stream); })();

        foreach (['abcdefghijklmnop123456', 'hunter2pass', 'p4ss', 'kafka-secret', 'deadbeef', 'zzzzzzzzzzzz'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertStringContainsString('visible', $raw);
        $this->assertStringContainsString(Redactor::MASK, $raw);
    }

    public function test_metrics_render_valid_prometheus_text(): void
    {
        Metrics::inc('nl_requests_total', ['method' => 'GET', 'status' => '200'], 2, 'HTTP requests');
        Metrics::gauge('nl_backlog', 7, [], 'Backlog');
        Metrics::observe('nl_duration_seconds', 0.03, ['route' => 'orders'], 'Duration');
        Metrics::observe('nl_duration_seconds', 3.0, ['route' => 'orders']);

        $text = Metrics::render();

        $this->assertStringContainsString('# TYPE nl_requests_total counter', $text);
        $this->assertStringContainsString('nl_requests_total{method="GET",status="200"} 2', $text);
        $this->assertStringContainsString('nl_backlog 7', $text);
        $this->assertStringContainsString('nl_duration_seconds_bucket{route="orders",le="0.05"} 1', $text);
        $this->assertStringContainsString('nl_duration_seconds_bucket{route="orders",le="+Inf"} 2', $text);
        $this->assertStringContainsString('nl_duration_seconds_count{route="orders"} 2', $text);
        $this->assertMatchesRegularExpression('/nl_duration_seconds_sum\{route="orders"\} 3\.03/', $text);
    }

    public function test_a_broken_metrics_store_never_breaks_the_caller(): void
    {
        config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nope'], 'kafka.metrics.store' => 'broken']);

        Metrics::inc('x_total');
        Metrics::observe('x_seconds', 0.1);

        $this->assertGreaterThan(0, Metrics::failures());
    }

    public function test_trace_context_is_w3c_compliant_and_continues_across_hops(): void
    {
        $root = TraceContext::continueFrom(null);
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $root->traceparent());

        $hop = TraceContext::continueFrom($root->traceparent());
        $this->assertSame($root->traceId, $hop->traceId, 'same trace across HTTP/Kafka boundary');
        $this->assertNotSame($root->spanId, $hop->spanId);
        $this->assertSame($root->spanId, $hop->parentSpanId);

        $this->assertNull(TraceContext::parse('garbage'));
        $this->assertNull(TraceContext::parse('00-'.str_repeat('0', 32).'-'.str_repeat('1', 16).'-01'));
    }

    public function test_spans_are_exported_over_otlp_when_enabled_and_dropped_when_not(): void
    {
        Http::fake(['collector.test:4318/*' => Http::response('{}', 200)]);

        // disabled → nothing buffered, nothing sent
        Tracer::span('noop', [], fn () => 1);
        $this->assertSame([], Tracer::buffered());

        config(['kafka.otel.enabled' => true, 'kafka.otel.endpoint' => 'http://collector.test:4318', 'app.name' => 'orders']);
        $trace = TraceContext::continueFrom(null);
        TraceContext::activate($trace);

        Tracer::span('outer', ['k' => 'v'], function () {
            Tracer::span('inner', ['db.system' => 'pgsql'], fn () => null);
        });
        $this->assertSame(2, Tracer::flush());

        Http::assertSent(function ($request) use ($trace) {
            $spans = $request['resourceSpans'][0]['scopeSpans'][0]['spans'];

            $byName = array_column($spans, null, 'name');

            return str_ends_with($request->url(), '/v1/traces')
                && count($spans) === 2
                && $byName['outer']['traceId'] === $trace->traceId
                && $byName['inner']['traceId'] === $trace->traceId
                && $byName['inner']['parentSpanId'] === $byName['outer']['spanId'];
        });
    }

    public function test_exporter_failure_is_swallowed(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        config(['kafka.otel.enabled' => true, 'kafka.otel.endpoint' => 'http://collector.test:4318']);

        Tracer::span('x', [], fn () => 1);

        $this->assertSame(0, Tracer::flush(), 'telemetry outage must not raise');
    }
}
