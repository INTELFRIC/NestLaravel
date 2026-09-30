<?php

namespace NestLaravel\Kafka\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NestLaravel\Kafka\Observability\Metrics;
use Throwable;

/**
 * Cache access that degrades instead of failing the request when the cache backend (usually Redis) is unavailable.
 *
 *   Redis up        → normal caching.
 *   Redis down /    → the value is computed from the source of truth (slower, still correct); errors are logged and
 *   timing out        counted; after a failure the cache is BYPASSED for `bypass_seconds` so a dead Redis costs one
 *                     timeout, not one per call.
 *   Redis restarts  → data is gone (a cache is disposable), the first calls recompute and repopulate.
 *
 * Use it for OPTIONAL caches (lookups, computed views, rate-like counters where approximation is fine). Do NOT use it for
 * things that need the store to be correct — distributed locks, idempotency, replay protection, sessions — those must
 * fail closed (the gateway/service middleware already do).
 */
final class SafeCache
{
    private static int $bypassUntil = 0;

    public function __construct(private readonly ?string $store = null, private readonly int $bypassSeconds = 10) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $key, int $ttlSeconds, Closure $compute): mixed
    {
        if ($this->bypassed()) {
            return $compute();
        }

        try {
            return Cache::store($this->store ?: null)->remember($key, $ttlSeconds, $compute);
        } catch (Throwable $e) {
            $this->degraded($e, 'remember');

            return $compute();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->bypassed()) {
            return $default;
        }

        try {
            return Cache::store($this->store ?: null)->get($key, $default);
        } catch (Throwable $e) {
            $this->degraded($e, 'get');

            return $default;
        }
    }

    public function put(string $key, mixed $value, int $ttlSeconds): bool
    {
        if ($this->bypassed()) {
            return false;
        }

        try {
            return Cache::store($this->store ?: null)->put($key, $value, $ttlSeconds);
        } catch (Throwable $e) {
            $this->degraded($e, 'put');

            return false;
        }
    }

    public function forget(string $key): bool
    {
        if ($this->bypassed()) {
            return false;
        }

        try {
            return Cache::store($this->store ?: null)->forget($key);
        } catch (Throwable $e) {
            $this->degraded($e, 'forget');

            return false;
        }
    }

    public static function resetBypass(): void
    {
        self::$bypassUntil = 0;
    }

    private function bypassed(): bool
    {
        return self::$bypassUntil > time();
    }

    private function degraded(Throwable $e, string $operation): void
    {
        self::$bypassUntil = time() + $this->bypassSeconds;
        Metrics::inc('nestlaravel_cache_errors_total', ['operation' => $operation], help: 'Cache backend failures (served from source)');
        Log::warning('Cache unavailable; serving from the source of truth', ['operation' => $operation, 'bypass_seconds' => $this->bypassSeconds, 'error' => $e->getMessage()]);
    }
}
