<?php

namespace NestLaravel\Kafka\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Explicit transaction retry policy.
 *
 * A database deadlock / serialization failure aborts the transaction; re-running the callback is safe ONLY if the
 * callback's effects are limited to that transaction. If it also calls an external API, sends mail, charges a card…
 * a retry would repeat those effects, so that must be a deliberate, named choice:
 *
 *   Transactions::idempotent(fn () => …, attempts: 3);   // DB-only body: deadlocks are retried
 *   Transactions::once(fn () => …);                      // may have external side effects: never re-run automatically
 *
 * (Laravel's own `DB::transaction($cb, $attempts)` retries silently; these helpers make the contract visible.)
 */
final class Transactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $body  must have NO effects outside this database transaction
     * @return T
     */
    public static function idempotent(Closure $body, int $attempts = 3): mixed
    {
        return DB::transaction($body, max(1, $attempts));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $body
     * @return T
     */
    public static function once(Closure $body): mixed
    {
        return DB::transaction($body, 1);
    }
}
