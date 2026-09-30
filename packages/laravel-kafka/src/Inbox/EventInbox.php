<?php

namespace NestLaravel\Kafka\Inbox;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use Throwable;

/**
 * Transactional inbox: guarantees a business effect happens at most once per (consumer, event_id) even though
 * Kafka delivers at-least-once.
 *
 *   $inbox->process($event['event_id'], fn () => $this->reserveStock($event));
 *
 * How it works (one database transaction, the SAME database as your business tables):
 *
 *   BEGIN
 *     INSERT ... ON CONFLICT DO NOTHING   -- unique (consumer, event_id)
 *     0 rows?  → duplicate: COMMIT, skip handler, caller ACKs
 *     handler()                           -- business writes
 *   COMMIT                                -- business writes + dedup record become visible together
 *
 *   • handler throws / process crashes / DB rolls back → the dedup row disappears with it, the offset is not
 *     committed, the retry runs the handler again exactly once.
 *   • two workers get the same event concurrently → the second INSERT waits on the unique index until the first
 *     transaction ends, then sees the row (skip) or, if the first rolled back, proceeds.
 *
 * Side effects OUTSIDE the database (HTTP calls, emails) are not covered by the transaction: publish follow-up
 * events through the outbox (same transaction) and make external calls idempotent.
 */
final class EventInbox
{
    public function __construct(
        private readonly string $table = 'inbox_events',
        private readonly string $defaultConsumer = 'default',
        private readonly int $deadlockAttempts = 1,
    ) {}

    /**
     * @param  Closure(): mixed  $handler
     * @param  array{event_type?: string, correlation_id?: string|null, tenant_id?: string|null}  $meta
     */
    public function process(string $eventId, Closure $handler, ?string $consumer = null, array $meta = []): InboxResult
    {
        $consumer ??= $this->defaultConsumer;
        $context = ['consumer' => $consumer, 'event_id' => $eventId] + array_filter([
            'event_type' => $meta['event_type'] ?? null,
            'correlation_id' => $meta['correlation_id'] ?? null,
        ]);

        try {
            $result = DB::transaction(function () use ($eventId, $handler, $consumer, $meta): InboxResult {
                $inserted = DB::table($this->table)->insertOrIgnore([
                    'consumer' => $consumer,
                    'event_id' => $eventId,
                    'event_type' => $meta['event_type'] ?? null,
                    'correlation_id' => $meta['correlation_id'] ?? null,
                    'tenant_id' => $meta['tenant_id'] ?? null,
                    'processed_at' => now(),
                ]);

                if ($inserted === 0) {
                    return InboxResult::Duplicate;
                }

                $handler();

                return InboxResult::Processed;
            }, max(1, $this->deadlockAttempts));
        } catch (Throwable $e) {
            Metrics::inc('nestlaravel_inbox_total', ['consumer' => $consumer, 'result' => 'failed'], help: 'Inbox executions by result');
            Log::warning('Inbox handler failed; transaction rolled back, event will be retried', $context + ['error' => $e->getMessage(), 'exception' => $e]);

            throw $e;
        }

        Metrics::inc('nestlaravel_inbox_total', ['consumer' => $consumer, 'result' => $result->value], help: 'Inbox executions by result');

        if ($result === InboxResult::Duplicate) {
            Log::info('Duplicate event skipped by inbox', $context);
        }

        return $result;
    }

    public function hasProcessed(string $eventId, ?string $consumer = null): bool
    {
        return DB::table($this->table)
            ->where('consumer', $consumer ?? $this->defaultConsumer)
            ->where('event_id', $eventId)
            ->exists();
    }

    /** Delete dedup records older than $days. Keep this comfortably above topic retention / max redelivery age. */
    public function prune(int $days): int
    {
        return DB::table($this->table)->where('processed_at', '<', now()->subDays(max(1, $days)))->delete();
    }

    public function withContext(array $values, Closure $callback): mixed
    {
        return LogContext::with($values, $callback);
    }
}
