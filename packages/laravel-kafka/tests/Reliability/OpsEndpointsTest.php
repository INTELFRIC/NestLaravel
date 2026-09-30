<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;

class OpsEndpointsTest extends ReliabilityTestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->get('/api/ping', fn () => response()->json(['pong' => true]));
        $router->middleware('api')->get('/api/boom', fn () => throw new \RuntimeException('kaput'));
    }

    /** Point the health check at an unreachable connection without touching the test's own DB connection. */
    private function breakDatabase(): void
    {
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/x.sqlite'],
            'kafka.health.database_connection' => 'broken',
        ]);
    }

    public function test_liveness_never_depends_on_external_systems(): void
    {
        // Every dependency broken:
        $this->breakDatabase();
        config(['kafka.enabled' => true, 'kafka.brokers' => '127.0.0.1:1', 'kafka.health.required' => ['database', 'kafka']]);

        $this->getJson('/liveness')->assertOk()->assertJson(['status' => 'alive']);
    }

    public function test_readiness_fails_only_for_required_dependencies(): void
    {
        config(['kafka.enabled' => true, 'kafka.brokers' => '127.0.0.1:1', 'kafka.health.timeout_ms' => 200]);

        // Kafka is down but NOT required → still ready (a service can serve HTTP without the broker).
        config(['kafka.health.required' => ['database']]);
        $this->getJson('/readiness')->assertOk()->assertJsonPath('checks.database.status', 'ok');

        // Required → not ready, and the response does not leak the broker address.
        config(['kafka.health.required' => ['database', 'kafka']]);
        $response = $this->getJson('/readiness')->assertStatus(503);
        $this->assertSame('fail', $response->json('checks.kafka.status'));
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
    }

    public function test_readiness_reports_a_down_database(): void
    {
        $this->breakDatabase();

        $this->getJson('/readiness')->assertStatus(503)->assertJsonPath('checks.database.status', 'fail');
        $this->getJson('/health')->assertStatus(503);
    }

    public function test_unknown_required_dependency_is_reported_not_ignored(): void
    {
        config(['kafka.health.required' => ['database', 'typo']]);

        $this->getJson('/readiness')->assertStatus(503)->assertJsonPath('checks.typo.status', 'fail');
    }

    public function test_startup_requires_the_reliability_tables_when_kafka_is_enabled(): void
    {
        config(['kafka.enabled' => true]);
        $this->getJson('/startup')->assertOk();

        \Illuminate\Support\Facades\Schema::drop('inbox_events');
        $this->getJson('/startup')->assertStatus(503)->assertJsonPath('checks.migrations.status', 'fail');
    }

    public function test_full_health_reports_outbox_backlog_and_degraded_state(): void
    {
        $this->app->make(\NestLaravel\Kafka\Contracts\EventBus::class)->publish(new PaymentCompleted('o-1'));
        config(['kafka.enabled' => true, 'kafka.brokers' => '127.0.0.1:1', 'kafka.health.timeout_ms' => 200]);

        $r = $this->getJson('/health')->assertOk();   // kafka optional → degraded, not failed

        $this->assertSame('degraded', $r->json('status'));
        $this->assertSame(1, $r->json('checks.outbox.pending'));
    }

    public function test_metrics_endpoint_is_disabled_without_a_token_and_authenticated_with_one(): void
    {
        $this->getJson('/metrics')->assertNotFound();

        config(['kafka.metrics.token' => 's3cret-scrape-token']);
        $this->get('/metrics')->assertStatus(401);
        $this->get('/metrics', ['Authorization' => 'Bearer wrong'])->assertStatus(401);

        $this->getJson('/api/ping');
        $body = $this->get('/metrics', ['Authorization' => 'Bearer s3cret-scrape-token'])->assertOk()->getContent();

        $this->assertStringContainsString('nestlaravel_http_requests_total{method="GET",route="/api/ping",status="200"} 1', $body);
        $this->assertStringContainsString('nestlaravel_http_request_duration_seconds_count', $body);
        $this->assertStringContainsString('nestlaravel_database_up 1', $body);
        $this->assertStringContainsString('nestlaravel_outbox_pending', $body);
    }

    public function test_request_and_trace_ids_are_accepted_when_sane_generated_otherwise_and_echoed(): void
    {
        $trace = '00-'.str_repeat('a', 32).'-'.str_repeat('b', 16).'-01';
        $r = $this->getJson('/api/ping', ['X-Request-ID' => 'req-abc-123456', 'X-Correlation-ID' => 'corr-abc-123456', 'traceparent' => $trace]);

        $r->assertHeader('X-Request-ID', 'req-abc-123456')->assertHeader('X-Correlation-ID', 'corr-abc-123456');
        $this->assertStringStartsWith('00-'.str_repeat('a', 32).'-', $r->headers->get('traceparent'), 'same trace continues');

        // Hostile / malformed ids (log injection) are replaced, never echoed.
        $bad = $this->getJson('/api/ping', ['X-Request-ID' => "x\nInjected: 1", 'X-Correlation-ID' => '<script>']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $bad->headers->get('X-Request-ID'));
        $this->assertSame($bad->headers->get('X-Request-ID'), $bad->headers->get('X-Correlation-ID'));
    }

    public function test_server_errors_are_counted(): void
    {
        $this->withoutExceptionHandling([\RuntimeException::class]);
        try {
            $this->getJson('/api/boom');
        } catch (\Throwable) {
        }

        $this->assertSame(1, Metrics::value('nestlaravel_http_errors_total', ['route' => '/api/boom']));
    }
}
