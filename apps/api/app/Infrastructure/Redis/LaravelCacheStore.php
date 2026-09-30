<?php

namespace App\Infrastructure\Redis;

use App\Core\Contracts\CacheStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class LaravelCacheStore implements CacheStore
{
    public function __construct(
        private readonly ?string $store = null,
    ) {}

    public function get(string $key): mixed
    {
        return $this->cache()->get($key);
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $this->cache()->put($key, $value, $ttl);
    }

    public function forget(string $key): void
    {
        $this->cache()->forget($key);
    }

    public function has(string $key): bool
    {
        return $this->cache()->has($key);
    }

    private function cache(): Repository
    {
        return Cache::store($this->store ?: config('cache.default'));
    }
}
