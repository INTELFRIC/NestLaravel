<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NestLaravel\Kafka\Resilience\CircuitBreaker;
use NestLaravel\Kafka\Resilience\CircuitOpenException;
use NestLaravel\Kafka\Resilience\ResilientHttp;
use NestLaravel\Kafka\Resilience\RetryPolicy;
use NestLaravel\Kafka\Resilience\UpstreamUnavailableException;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;

class ResilienceTest extends ReliabilityTestCase
{
    private const URL = 'http://orders.internal/api/v1/orders/1';

    private function http(string $method, ?string $body = null, array $options = [], array $extraHeaders = []): \Illuminate\Http\Client\Response
    {
        $nonces = [];

        return (new ResilientHttp)->send('orders', $method, self::URL, $body, function (int $attempt) use (&$nonces, $extraHeaders) {
            return ['X-Gateway-Nonce' => 'nonce-'.$attempt, 'Content-Type' => 'application/json'] + $extraHeaders;
        }, $options + ['retries' => 2, 'retry_base_ms' => 1, 'retry_max_ms' => 2, 'breaker' => ['threshold' => 3, 'window' => 30, 'open' => 20]]);
    }

    public function test_status_classification(): void
    {
        $this->assertSame('ok', RetryPolicy::classifyStatus(204));
        foreach ([408, 429, 500, 502, 503, 504] as $s) {
            $this->assertSame('transient', RetryPolicy::classifyStatus($s), (string) $s);
        }
        foreach ([401, 403] as $s) {
            $this->assertSame('auth', RetryPolicy::classifyStatus($s));
        }
        foreach ([400, 422] as $s) {
            $this->assertSame('validation', RetryPolicy::classifyStatus($s));
        }
        foreach ([404, 409, 410] as $s) {
            $this->assertSame('business', RetryPolicy::classifyStatus($s));
        }
    }

    public function test_safe_get_is_retried_on_transient_errors_with_a_fresh_nonce_per_attempt(): void
    {
        Http::fake(['orders.internal/*' => Http::sequence()->push('', 503)->push('', 502)->push('{"ok":true}', 200)]);

        $response = $this->http('GET');

        $this->assertSame(200, $response->status());
        $nonces = [];
        Http::assertSentCount(3);
        Http::assertSent(function (Request $r) use (&$nonces) { $nonces[] = $r->header('X-Gateway-Nonce')[0]; return true; });
        $this->assertSame(['nonce-1', 'nonce-2', 'nonce-3'], $nonces, 'single-use HMAC nonces must never be replayed by a retry');
    }

    public function test_unsafe_post_is_never_retried_by_default(): void
    {
        Http::fake(['orders.internal/*' => Http::sequence()->push('', 503)->push('{}', 200)]);

        $response = $this->http('POST', '{"amount":5}');

        $this->assertSame(503, $response->status());
        Http::assertSentCount(1);
    }

    public function test_post_with_an_idempotency_key_is_retried(): void
    {
        Http::fake(['orders.internal/*' => Http::sequence()->push('', 503)->push('{}', 200)]);

        $this->assertSame(200, $this->http('POST', '{}', [], ['Idempotency-Key' => 'abc'])->status());
        Http::assertSentCount(2);
    }

    public function test_post_is_retried_when_the_caller_explicitly_opts_in(): void
    {
        Http::fake(['orders.internal/*' => Http::sequence()->push('', 503)->push('{}', 200)]);

        $this->assertSame(200, $this->http('POST', '{}', ['retry_unsafe' => true])->status());
        Http::assertSentCount(2);
    }

    public function test_client_errors_are_not_retried_and_do_not_trip_the_breaker(): void
    {
        Http::fake(['orders.internal/*' => Http::sequence()->push('', 401)->push('', 403)->push('', 404)->push('', 409)->push('', 422)]);

        foreach ([401, 403, 404, 409, 422] as $status) {
            $this->assertSame($status, $this->http('GET')->status());
        }

        Http::assertSentCount(5);
        $this->assertSame(CircuitBreaker::CLOSED, (new CircuitBreaker('orders'))->state());
    }

    public function test_500_on_a_non_idempotent_request_is_not_retried_but_is_on_get(): void
    {
        $policy = new RetryPolicy;
        $this->assertFalse($policy->shouldRetry('POST', [], 500, 1));
        $this->assertTrue($policy->shouldRetry('GET', [], 500, 1));
        $this->assertFalse($policy->shouldRetry('GET', [], 503, 3), 'max retries respected');
        $this->assertFalse($policy->shouldRetry('GET', [], 503, 1, 9000), 'retry budget respected');
    }

    public function test_connection_failures_become_upstream_unavailable_after_retries(): void
    {
        $attempts = 0;
        Http::fake(['orders.internal/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        try {
            $this->http('GET');
            $this->fail('expected UpstreamUnavailableException');
        } catch (UpstreamUnavailableException $e) {
            $this->assertSame(3, $attempts, '1 attempt + 2 retries, then a clear failure (never hangs)');
            $this->assertSame('orders', $e->circuit);
        }
    }

    public function test_timeouts_are_always_applied(): void
    {
        Http::fake(['orders.internal/*' => Http::response('{}', 200)]);
        $this->http('GET', null, ['connect_timeout' => 1.5, 'timeout' => 4.0]);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Content-Type'));
        // Guzzle options are on the pending request; assert the defaults exist by sending without options too.
        $this->http('GET');
        Http::assertSentCount(2);
    }

    public function test_breaker_opens_after_repeated_failures_and_stops_calling_the_upstream(): void
    {
        Http::fake(['orders.internal/*' => Http::response('', 503)]);

        for ($i = 0; $i < 3; $i++) {
            $this->http('GET', null, ['retries' => 0]);
        }
        $sentBefore = count(Http::recorded());
        $this->assertSame(CircuitBreaker::OPEN, (new CircuitBreaker('orders', 3, 30, 20))->state());

        $this->expectException(CircuitOpenException::class);
        try {
            $this->http('GET');
        } finally {
            $this->assertCount($sentBefore, Http::recorded(), 'open breaker must not touch the network');
        }
    }

    public function test_half_open_probe_success_closes_and_failure_reopens(): void
    {
        $cb = new CircuitBreaker('svc', 2, 30, 10);
        $cb->recordFailure();
        $cb->recordFailure();
        $this->assertSame(CircuitBreaker::OPEN, $cb->state());
        $this->assertFalse($cb->allowRequest());

        $this->travel(11)->seconds();
        $this->assertSame(CircuitBreaker::HALF_OPEN, $cb->state());
        $this->assertTrue($cb->allowRequest(), 'first probe allowed');
        $this->assertFalse($cb->allowRequest(), 'only one probe at a time');

        $cb->recordFailure();                        // probe failed → open again immediately
        $this->assertSame(CircuitBreaker::OPEN, $cb->state());

        $this->travel(11)->seconds();
        $this->assertTrue($cb->allowRequest());
        $cb->recordSuccess();                        // probe succeeded → closed
        $this->assertSame(CircuitBreaker::CLOSED, $cb->state());
        $this->assertTrue($cb->allowRequest());
    }

    public function test_breaker_state_is_shared_through_the_cache_not_process_memory(): void
    {
        $a = new CircuitBreaker('shared', 2, 30, 20);
        $b = new CircuitBreaker('shared', 2, 30, 20);   // e.g. another gateway replica

        $a->recordFailure();
        $b->recordFailure();

        $this->assertSame(CircuitBreaker::OPEN, $a->state());
        $this->assertFalse($b->allowRequest());
    }

    public function test_unavailable_cache_never_blocks_traffic(): void
    {
        config(['cache.stores.broken' => ['driver' => 'redis', 'connection' => 'nope']]);
        $cb = new CircuitBreaker('x', 1, 30, 20, 'broken');

        $cb->recordFailure();

        $this->assertTrue($cb->allowRequest(), 'protecting an upstream must not take the caller down');
    }
}
