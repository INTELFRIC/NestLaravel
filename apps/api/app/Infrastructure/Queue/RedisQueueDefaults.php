<?php

namespace App\Infrastructure\Queue;

/**
 * Recommended Laravel queue settings when using Redis as the queue backend.
 * Configure via QUEUE_CONNECTION=redis in .env (see WIRING.md).
 */
final class RedisQueueDefaults
{
    public const CONNECTION = 'redis';

    public const RETRY_AFTER = 90;

    public const BLOCK_FOR = 5;

    /**
     * @return array<string, mixed>
     */
    public static function connectionConfig(string $queue = 'default'): array
    {
        return [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', $queue),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', self::RETRY_AFTER),
            'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', self::BLOCK_FOR),
            'after_commit' => true,
        ];
    }
}
