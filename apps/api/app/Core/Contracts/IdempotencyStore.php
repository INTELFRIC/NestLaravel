<?php

namespace App\Core\Contracts;

interface IdempotencyStore
{
    public function has(string $key): bool;

    public function remember(string $key, int $ttlSeconds = 86400): void;
}
