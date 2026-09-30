<?php

namespace App\Core\Contracts;

interface CacheStore
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $ttl): void;

    public function forget(string $key): void;

    public function has(string $key): bool;
}
