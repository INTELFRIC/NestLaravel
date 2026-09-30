<?php

namespace NestLaravel\Kafka\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Observability\TraceContext;
use NestLaravel\Kafka\Observability\Tracer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Per-request observability, registered on the `api` group by the kit:
 *
 *  • request id / correlation id (accepted from the caller only if they look sane, otherwise generated)
 *  • W3C trace context (continues the caller's trace, creates one otherwise) + optional OTLP span
 *  • log context (request_id, correlation_id, trace_id, span_id) and response headers
 *  • RED metrics: request count, latency histogram, errors — labelled by route TEMPLATE (low cardinality)
 *
 * State is cleared in terminate() so long-lived workers (Octane/Swoole) never leak one request's ids into the next.
 */
final class ObserveRequest
{
    private const ID = '/^[A-Za-z0-9._:-]{8,80}$/';

    private int $startedAt = 0;

    public function handle(Request $request, Closure $next): Response
    {
        $this->startedAt = hrtime(true);

        $requestId = $this->sane($request->headers->get('X-Request-ID')) ?? (string) Str::uuid();
        $correlationId = $this->sane($request->headers->get('X-Correlation-ID')) ?? $requestId;

        app()->instance('request_id', $requestId);
        app()->instance('correlation_id', $correlationId);
        LogContext::set(['correlation_id' => $correlationId]);

        $trace = TraceContext::continueFrom($request->headers->get('traceparent'));
        TraceContext::activate($trace);

        $route = null;

        try {
            /** @var Response $response */
            $response = Tracer::span('http.server '.$request->method(), ['http.method' => $request->method()], function () use ($request, $next, &$route) {
                $response = $next($request);
                $route = $request->route()?->uri();

                return $response;
            }, 'server');
        } catch (Throwable $e) {
            $this->record($request, 500, $route ?? $request->route()?->uri());

            throw $e;
        }

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('traceparent', $trace->traceparent());

        $this->record($request, $response->getStatusCode(), $route);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        Tracer::flush();
        LogContext::forget('correlation_id', 'trace_id', 'span_id', 'tenant_id', 'event_id', 'causation_id');
        TraceContext::activate(null);
    }

    private function record(Request $request, int $status, ?string $route): void
    {
        $labels = ['method' => $request->method(), 'route' => '/'.ltrim($route ?? 'unmatched', '/'), 'status' => (string) $status];

        Metrics::inc('nestlaravel_http_requests_total', $labels, help: 'HTTP requests handled');
        Metrics::observe('nestlaravel_http_request_duration_seconds', (hrtime(true) - $this->startedAt) / 1e9, ['method' => $labels['method'], 'route' => $labels['route']], 'HTTP request latency');

        if ($status >= 500) {
            Metrics::inc('nestlaravel_http_errors_total', ['route' => $labels['route']], help: 'HTTP 5xx responses');
        }
    }

    private function sane(?string $value): ?string
    {
        return $value !== null && preg_match(self::ID, $value) === 1 ? $value : null;
    }
}
