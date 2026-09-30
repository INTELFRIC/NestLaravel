<?php

namespace NestLaravel\Kafka\Observability;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional, dependency-free tracing. Disabled unless `kafka.otel.enabled` is true, in which case spans are
 * buffered in memory and exported once (per request / per consumed message) to an OTLP/HTTP collector.
 * Trace ids are propagated even when export is disabled, so logs and events stay correlated.
 *
 * If you prefer the official SDK, ignore this exporter and use TraceContext for propagation only.
 */
final class Tracer
{
    /** @var list<array<string, mixed>> */
    private static array $spans = [];

    public static function enabled(): bool
    {
        return (bool) config('kafka.otel.enabled', false) && (string) config('kafka.otel.endpoint', '') !== '';
    }

    /**
     * Run $fn inside a child span of the current trace.
     *
     * @template T
     *
     * @param  array<string, scalar>  $attributes
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function span(string $name, array $attributes, Closure $fn, string $kind = 'internal'): mixed
    {
        $parent = TraceContext::current();
        $context = $parent?->child() ?? TraceContext::continueFrom(null);
        TraceContext::activate($context);
        $start = (int) (microtime(true) * 1e9);
        $error = null;

        try {
            return $fn();
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            if (self::enabled()) {
                self::$spans[] = [
                    'traceId' => $context->traceId,
                    'spanId' => $context->spanId,
                    'parentSpanId' => $context->parentSpanId ?? '',
                    'name' => $name,
                    'kind' => ['internal' => 1, 'server' => 2, 'client' => 3, 'producer' => 4, 'consumer' => 5][$kind] ?? 1,
                    'startTimeUnixNano' => (string) $start,
                    'endTimeUnixNano' => (string) (int) (microtime(true) * 1e9),
                    'attributes' => self::attributes($attributes + ($error ? ['error' => true, 'exception.type' => $error::class] : [])),
                    'status' => $error ? ['code' => 2, 'message' => Redactor::redactString($error->getMessage())] : ['code' => 1],
                ];
            }
            TraceContext::activate($parent);
        }
    }

    /** Export buffered spans (call from terminating callbacks / after each message). */
    public static function flush(): int
    {
        if (self::$spans === []) {
            return 0;
        }

        $spans = self::$spans;
        self::$spans = [];

        if (! self::enabled()) {
            return 0;
        }

        $payload = [
            'resourceSpans' => [[
                'resource' => ['attributes' => self::attributes([
                    'service.name' => (string) (config('kafka.otel.service_name') ?: config('app.name')),
                    'deployment.environment' => (string) config('app.env'),
                ])],
                'scopeSpans' => [['scope' => ['name' => 'nestlaravel'], 'spans' => $spans]],
            ]],
        ];

        try {
            Http::withHeaders((array) config('kafka.otel.headers', []))
                ->timeout((int) config('kafka.otel.timeout_seconds', 2))
                ->connectTimeout(1)
                ->asJson()
                ->post(rtrim((string) config('kafka.otel.endpoint'), '/').'/v1/traces', $payload)
                ->throw();
        } catch (Throwable $e) {
            // Telemetry must never break the request; surface it once in the logs.
            Log::warning('OTLP span export failed', ['error' => $e->getMessage(), 'spans' => count($spans)]);

            return 0;
        }

        return count($spans);
    }

    /** @return list<array<string, mixed>> */
    public static function buffered(): array
    {
        return self::$spans;
    }

    public static function reset(): void
    {
        self::$spans = [];
        TraceContext::activate(null);
    }

    /** @param array<string, scalar> $attributes */
    private static function attributes(array $attributes): array
    {
        $out = [];
        foreach (Redactor::redact($attributes) as $k => $v) {
            $out[] = ['key' => (string) $k, 'value' => is_bool($v) ? ['boolValue' => $v] : (is_int($v) ? ['intValue' => (string) $v] : ['stringValue' => (string) $v])];
        }

        return $out;
    }
}
