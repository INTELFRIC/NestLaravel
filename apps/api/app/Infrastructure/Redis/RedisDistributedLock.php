<?php

namespace App\Infrastructure\Redis;

use App\Core\Contracts\DistributedLock;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class RedisDistributedLock implements DistributedLock
{
    /** @var array<string, string> */
    private array $tokens = [];

    public function __construct(
        private readonly string $prefix = 'lock:',
        private readonly ?string $connection = null,
    ) {}

    public function acquire(string $key, int $ttlSeconds = 30): bool
    {
        $token = bin2hex(random_bytes(16));
        $lockKey = $this->prefixed($key);

        $result = $this->redis()->set(
            $lockKey,
            $token,
            'EX',
            max(1, $ttlSeconds),
            'NX'
        );

        $acquired = $result === true || $result === 'OK';

        if ($acquired) {
            $this->tokens[$lockKey] = $token;
        }

        return $acquired;
    }

    public function release(string $key): void
    {
        $lockKey = $this->prefixed($key);
        $token = $this->tokens[$lockKey] ?? null;

        if ($token === null) {
            $this->redis()->del($lockKey);

            return;
        }

        // Release only if we still own the lock.
        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end
return 0
LUA;

        $this->redis()->eval($script, 1, $lockKey, $token);
        unset($this->tokens[$lockKey]);
    }

    public function run(string $key, callable $callback, int $ttlSeconds = 30): mixed
    {
        if (! $this->acquire($key, $ttlSeconds)) {
            throw new RuntimeException("Unable to acquire distributed lock [{$key}].");
        }

        try {
            return $callback();
        } finally {
            $this->release($key);
        }
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
