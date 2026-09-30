<?php

namespace NestLaravel\Kafka;

use Illuminate\Support\Facades\Cache;
use NestLaravel\Kafka\Contracts\IdempotencyStore;

/**
 * Idempotency backed by the service's own cache store (Redis in production).
 * Use a shared, persistent store: an ephemeral store weakens duplicate suppression.
 */
final class CacheIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly string $prefix = 'kafka:processed:',
        private readonly ?string $store = null,
    ) {}

    public function has(string $key): bool
    {
        return Cache::store($this->store ?: null)->has($this->prefix.$key);
    }

    public function remember(string $key, int $ttlSeconds = 86400): void
    {
        Cache::store($this->store ?: null)->put($this->prefix.$key, 1, max(1, $ttlSeconds));
    }
}
