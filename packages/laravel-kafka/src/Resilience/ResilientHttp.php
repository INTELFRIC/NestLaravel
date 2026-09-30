<?php

namespace NestLaravel\Kafka\Resilience;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Observability\TraceContext;

/**
 * The only way framework code should call another service over HTTP:
 *   explicit connect + total timeouts · classified retries with backoff (never for unsafe methods) · shared circuit
 *   breaker · fresh per-attempt headers (HMAC nonces are single-use) · W3C trace propagation · metrics.
 *
 * Nothing waits forever: a request is bounded by `connect_timeout + timeout` per attempt and by the retry budget overall.
 */
final class ResilientHttp
{
    /**
     * @param  array{connect_timeout?: float, timeout?: float, retries?: int, retry_base_ms?: int, retry_max_ms?: int, retry_unsafe?: bool, budget_ms?: int, breaker?: array{threshold?: int, window?: int, open?: int}, cache_store?: string|null}  $options
     * @param  Closure(int $attempt): array<string, string>  $headersFor  build headers (incl. fresh signature) per attempt
     */
    public function send(string $circuit, string $method, string $url, ?string $body, Closure $headersFor, array $options = []): Response
    {
        $breaker = new CircuitBreaker(
            $circuit,
            (int) ($options['breaker']['threshold'] ?? 5),
            (int) ($options['breaker']['window'] ?? 30),
            (int) ($options['breaker']['open'] ?? 20),
            $options['cache_store'] ?? null,
        );
        $policy = new RetryPolicy(
            (int) ($options['retries'] ?? 2),
            (int) ($options['retry_base_ms'] ?? 100),
            (int) ($options['retry_max_ms'] ?? 2000),
            (bool) ($options['retry_unsafe'] ?? false),
            (int) ($options['budget_ms'] ?? 8000),
        );

        $started = hrtime(true);
        $attempt = 0;

        while (true) {
            $attempt++;

            if (! $breaker->allowRequest()) {
                throw new CircuitOpenException($circuit, $breaker->retryAfterSeconds());
            }

            $headers = $headersFor($attempt) + TraceContext::outgoingHeaders();
            $status = null;
            $response = null;
            $transportError = null;

            try {
                $response = Http::withHeaders($headers)
                    ->connectTimeout((float) ($options['connect_timeout'] ?? 2))
                    ->timeout((float) ($options['timeout'] ?? 10))
                    ->withoutRedirecting()
                    ->withBody((string) $body, $headers['Content-Type'] ?? 'application/json')
                    ->send($method, $url);
                $status = $response->status();
            } catch (ConnectionException $e) {
                $transportError = $e;
            }

            $failed = $transportError !== null || ($status !== null && $status >= 500);
            $failed ? $breaker->recordFailure() : $breaker->recordSuccess();

            Metrics::inc('nestlaravel_upstream_requests_total', ['circuit' => $circuit, 'outcome' => $transportError ? 'transport_error' : (string) $status], help: 'Inter-service HTTP calls');
            Metrics::observe('nestlaravel_upstream_seconds', (hrtime(true) - $started) / 1e9, ['circuit' => $circuit], 'Inter-service HTTP latency (all attempts)');

            $elapsedMs = (int) ((hrtime(true) - $started) / 1e6);

            if (! $failed && ($status === null || RetryPolicy::classifyStatus($status) !== RetryPolicy::TRANSIENT)) {
                return $response;
            }

            if ($policy->shouldRetry($method, $headers, $status, $attempt, $elapsedMs)) {
                Metrics::inc('nestlaravel_upstream_retries_total', ['circuit' => $circuit], help: 'Inter-service HTTP retries');
                $retryAfter = $response?->header('Retry-After');
                usleep($policy->delayMs($attempt, is_numeric($retryAfter) ? (int) $retryAfter : null) * 1000);

                continue;
            }

            if ($transportError !== null) {
                throw new UpstreamUnavailableException($circuit, $transportError->getMessage(), $transportError);
            }

            return $response;
        }
    }
}
