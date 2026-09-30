<?php

namespace App\Infrastructure\Redis;

use App\Core\Contracts\IdempotencyStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

final class RedisIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly string $prefix = 'idempotency:',
        private readonly ?string $connection = null,
    ) {}

    public function has(string $key): bool
    {
        return (bool) $this->redis()->exists($this->prefixed($key));
    }

    public function remember(string $key, int $ttlSeconds = 86400): void
    {
        $this->redis()->setex($this->prefixed($key), max(1, $ttlSeconds), '1');
    }

    private function prefixed(string $key): string
    {
        return $this->prefix.$key;
    }

    private function redis(): Connection
    {
        return Redis::connection($this->connection ?? 'default');
    }
}
