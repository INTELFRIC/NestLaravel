<?php

namespace NestLaravel\Kafka\Resilience;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use NestLaravel\Kafka\Observability\Metrics;

/**
 * Circuit breaker with state in the shared cache (Redis in production), so every gateway replica sees the same
 * state — no per-process local state, which is what lets the gateway scale horizontally.
 *
 *   CLOSED ──(threshold failures within window)──▶ OPEN ──(open_seconds elapsed)──▶ HALF-OPEN
 *      ▲                                             ▲                                  │
 *      └────────── probe succeeded ──────────────────┼──────────── probe failed ────────┘
 *
 * In HALF-OPEN exactly `half_open_probes` requests are let through (atomic Cache::add slot); everyone else is
 * short-circuited until a probe finishes. If the cache itself is unavailable the breaker fails OPEN-to-traffic
 * (i.e. lets requests through): protecting an upstream is never worth taking the caller down.
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half_open';

    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold = 5,
        private readonly int $windowSeconds = 30,
        private readonly int $openSeconds = 20,
        private readonly ?string $store = null,
    ) {}

    public function state(): string
    {
        try {
            $openedAt = $this->cache()->get($this->key('opened_at'));
            if ($openedAt === null) {
                return self::CLOSED;
            }

            return (now()->getTimestamp() - (int) $openedAt) >= $this->openSeconds ? self::HALF_OPEN : self::OPEN;
        } catch (\Throwable) {
            return self::CLOSED;
        }
    }

    /** May a request go through right now? */
    public function allowRequest(): bool
    {
        try {
            switch ($this->state()) {
                case self::CLOSED:
                    return true;
                case self::OPEN:
                    Metrics::inc('nestlaravel_circuit_rejected_total', ['circuit' => $this->name], help: 'Requests short-circuited by an open breaker');

                    return false;
                default: // half-open: one probe at a time
                    $slot = $this->cache()->add($this->key('probe'), 1, $this->openSeconds);
                    if (! $slot) {
                        Metrics::inc('nestlaravel_circuit_rejected_total', ['circuit' => $this->name], help: 'Requests short-circuited by an open breaker');
                    }

                    return $slot;
            }
        } catch (\Throwable) {
            return true;
        }
    }

    public function recordSuccess(): void
    {
        try {
            if ($this->cache()->get($this->key('opened_at')) !== null) {
                Metrics::inc('nestlaravel_circuit_transitions_total', ['circuit' => $this->name, 'to' => 'closed'], help: 'Breaker state changes');
            }
            $this->cache()->forget($this->key('opened_at'));
            $this->cache()->forget($this->key('probe'));
            $this->cache()->forget($this->key('failures'));
        } catch (\Throwable) {
        }
    }

    public function recordFailure(): void
    {
        try {
            $cache = $this->cache();

            // A failed half-open probe re-opens immediately.
            if ($this->state() === self::HALF_OPEN) {
                $this->open();

                return;
            }

            $cache->add($this->key('failures'), 0, $this->windowSeconds);
            $failures = (int) $cache->increment($this->key('failures'));

            if ($failures >= $this->failureThreshold) {
                $this->open();
            }
        } catch (\Throwable) {
        }
    }

    private function open(): void
    {
        $cache = $this->cache();
        $cache->put($this->key('opened_at'), now()->getTimestamp(), $this->openSeconds * 10);
        $cache->forget($this->key('probe'));
        $cache->forget($this->key('failures'));
        Metrics::inc('nestlaravel_circuit_transitions_total', ['circuit' => $this->name, 'to' => 'open'], help: 'Breaker state changes');
    }

    public function retryAfterSeconds(): int
    {
        try {
            $openedAt = (int) ($this->cache()->get($this->key('opened_at')) ?? 0);

            return max(1, $this->openSeconds - (now()->getTimestamp() - $openedAt));
        } catch (\Throwable) {
            return 1;
        }
    }

    private function key(string $suffix): string
    {
        return 'nlcb:'.$this->name.':'.$suffix;
    }

    private function cache(): Repository
    {
        return Cache::store($this->store ?: null);
    }
}
