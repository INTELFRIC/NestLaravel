<?php

namespace App\Infrastructure\Redis;

use App\Core\Contracts\IdempotencyStore;

/**
 * In-memory fallback until Redis IdempotencyStore is bound by Infrastructure.
 */
final class ArrayIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, int> */
    private array $keys = [];

    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function remember(string $key, int $ttlSeconds = 86400): void
    {
        $this->keys[$key] = time() + $ttlSeconds;
    }
}
