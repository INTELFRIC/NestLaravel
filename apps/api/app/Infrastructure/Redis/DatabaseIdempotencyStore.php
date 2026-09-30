<?php

namespace App\Infrastructure\Redis;

use App\Core\Contracts\IdempotencyStore;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Optional DB fallback using processed_events when Redis is unavailable.
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function has(string $key): bool
    {
        return DB::table('processed_events')
            ->where('event_id', $key)
            ->exists();
    }

    public function remember(string $key, int $ttlSeconds = 86400): void
    {
        try {
            DB::table('processed_events')->insertOrIgnore([
                'event_id' => $key,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Unique constraint race: treat as already remembered.
        }
    }
}
