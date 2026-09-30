<?php

namespace App\Core\Contracts;

interface DistributedLock
{
    public function acquire(string $key, int $ttlSeconds = 30): bool;

    public function release(string $key): void;

    public function run(string $key, callable $callback, int $ttlSeconds = 30): mixed;
}
