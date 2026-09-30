<?php

namespace NestLaravel\Kafka\Outbox;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\KafkaSerializer;
use NestLaravel\Kafka\KafkaTopic;
use NestLaravel\Kafka\Observability\Metrics;
use Throwable;

/**
 * Ships outbox rows to Kafka safely with any number of concurrent publishers.
 *
 *  1. recover  – rows stuck in `processing` longer than the visibility timeout (publisher crashed) → `pending`
 *  2. claim    – per row: `UPDATE … SET status='processing', locked_by=me WHERE id=? AND status='pending'`;
 *                exactly one publisher gets affected-rows = 1, so no two publishers ever hold the same row
 *                (portable: no SKIP LOCKED needed, works on SQLite/MySQL/PostgreSQL)
 *  3. produce + flush – broker-confirmed delivery (RdKafka delivery reports)
 *  4. finish   – `UPDATE … WHERE id=? AND locked_by=me`; a publisher that lost its claim cannot overwrite the new owner
 *  5. failure  – exponential backoff (base·2^(attempt-1), capped) until `max_attempts`, then `failed` + copy on the DLQ
 *
 * Delivery is at-least-once: a crash between flush and step 4 republishes the row after the visibility timeout,
 * which consumers absorb through the inbox (same event_id).
 */
final class OutboxPublisher
{
    private readonly string $workerId;

    private int $lastGaugeAt = 0;

    /** Test seam: runs between selecting candidate rows and claiming them (to reproduce publisher races). */
    private ?\Closure $betweenSelectAndClaim = null;

    public function __construct(
        private readonly KafkaProducer $producer,
        private readonly KafkaConfig $config,
        private readonly KafkaTopic $topics,
        private readonly KafkaSerializer $serializer = new KafkaSerializer,
        ?string $workerId = null,
    ) {
        $this->workerId = $workerId ?? substr(gethostname().':'.getmypid().':'.Str::random(6), 0, 64);
    }

    public function betweenSelectAndClaim(?\Closure $hook): void
    {
        $this->betweenSelectAndClaim = $hook;
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    /** @return int number of rows confirmed published */
    public function publishPending(?int $limit = null): int
    {
        $limit ??= $this->config->outboxBatchSize();

        $this->recoverStale();
        $claimed = $this->claim($limit);

        if ($claimed->isEmpty()) {
            $this->refreshGauges();

            return 0;
        }

        /** @var list<OutboxMessage> $sent */
        $sent = [];

        foreach ($claimed as $message) {
            try {
                $this->producer->produce($this->toKafkaMessage($message));
                $sent[] = $message;
            } catch (Throwable $e) {
                $this->handleFailure($message, $e);
            }
        }

        if ($sent === []) {
            $this->refreshGauges();

            return 0;
        }

        try {
            $this->producer->flush();
        } catch (Throwable $e) {
            foreach ($sent as $message) {
                $this->handleFailure($message, $e);
            }
            $this->refreshGauges();

            return 0;
        }

        $published = 0;
        foreach ($sent as $message) {
            $published += $this->finishPublished($message) ? 1 : 0;
        }

        Metrics::inc('nestlaravel_outbox_published_total', [], $published, 'Outbox rows confirmed published');
        $this->refreshGauges();

        return $published;
    }

    /** Public for tests/tools: move rows abandoned by a crashed publisher back to `pending`. */
    public function recoverStale(): int
    {
        $timeout = max(1, (int) $this->config->outboxVisibilityTimeoutSeconds());

        $recovered = OutboxMessage::query()
            ->where('status', OutboxMessage::STATUS_PROCESSING)
            ->where('locked_at', '<', now()->subSeconds($timeout))
            ->update([
                'status' => OutboxMessage::STATUS_PENDING,
                'locked_by' => null,
                'locked_at' => null,
                'last_error' => 'Recovered: publisher did not finish within the visibility timeout.',
            ]);

        if ($recovered > 0) {
            Log::warning('Recovered stale outbox rows', ['count' => $recovered, 'visibility_timeout_seconds' => $timeout]);
            Metrics::inc('nestlaravel_outbox_recovered_total', [], $recovered, 'Stale processing rows returned to pending');
        }

        return $recovered;
    }

    /**
     * Atomically claim up to $limit due rows for this publisher.
     *
     * @return Collection<int, OutboxMessage>
     */
    public function claim(int $limit): Collection
    {
        $ids = OutboxMessage::query()
            ->where('status', OutboxMessage::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($this->betweenSelectAndClaim !== null) {
            ($this->betweenSelectAndClaim)();
        }

        $claimed = [];

        foreach ($ids as $id) {
            $won = OutboxMessage::query()
                ->whereKey($id)
                ->where('status', OutboxMessage::STATUS_PENDING)
                ->update([
                    'status' => OutboxMessage::STATUS_PROCESSING,
                    'locked_by' => $this->workerId,
                    'locked_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                ]);

            if ($won === 1) {
                $claimed[] = $id;
            }
        }

        if ($claimed === []) {
            return new Collection;
        }

        return OutboxMessage::query()->whereIn('id', $claimed)->where('locked_by', $this->workerId)->orderBy('id')->get();
    }

    /** Publish exactly one already-claimed-or-pending row (kept for 1.0 API compatibility). */
    public function publishOne(OutboxMessage $message): bool
    {
        $message->refresh();
        $claimed = $this->claimSpecific($message->id);

        if ($claimed === null) {
            return false;
        }

        try {
            $this->producer->produce($this->toKafkaMessage($claimed));
            $this->producer->flush();
        } catch (Throwable $e) {
            $this->handleFailure($claimed, $e);

            return false;
        }

        return $this->finishPublished($claimed);
    }

    /** @return array{pending: int, processing: int, published: int, failed: int, oldest_pending_age_seconds: int|null, oldest_processing_age_seconds: int|null} */
    public function snapshot(): array
    {
        $counts = OutboxMessage::query()->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
        $oldest = OutboxMessage::query()->where('status', OutboxMessage::STATUS_PENDING)->min('created_at');
        $oldestProcessing = OutboxMessage::query()->where('status', OutboxMessage::STATUS_PROCESSING)->min('locked_at');

        return [
            'pending' => (int) ($counts[OutboxMessage::STATUS_PENDING] ?? 0),
            'processing' => (int) ($counts[OutboxMessage::STATUS_PROCESSING] ?? 0),
            'published' => (int) ($counts[OutboxMessage::STATUS_PUBLISHED] ?? 0),
            'failed' => (int) ($counts[OutboxMessage::STATUS_FAILED] ?? 0),
            'oldest_pending_age_seconds' => $oldest ? (int) now()->diffInSeconds($oldest, true) : null,
            'oldest_processing_age_seconds' => $oldestProcessing ? (int) now()->diffInSeconds($oldestProcessing, true) : null,
        ];
    }

    /** Delay before the next attempt: base · 2^(attempt-1), capped. */
    public function backoffSeconds(int $attempt): int
    {
        $base = max(1, $this->config->outboxRetryDelaySeconds());
        $cap = max($base, $this->config->outboxMaxRetryDelaySeconds());

        return (int) min($cap, $base * (2 ** max(0, $attempt - 1)));
    }

    // ------------------------------------------------------------------------------------------------------

    private function claimSpecific(int $id): ?OutboxMessage
    {
        $won = OutboxMessage::query()->whereKey($id)->where('status', OutboxMessage::STATUS_PENDING)->update([
            'status' => OutboxMessage::STATUS_PROCESSING,
            'locked_by' => $this->workerId,
            'locked_at' => now(),
            'attempts' => DB::raw('attempts + 1'),
        ]);

        return $won === 1 ? OutboxMessage::query()->find($id) : null;
    }

    /** Mark published only if we still own the claim. */
    private function finishPublished(OutboxMessage $message): bool
    {
        $done = OutboxMessage::query()
            ->whereKey($message->id)
            ->where('locked_by', $this->workerId)
            ->where('status', OutboxMessage::STATUS_PROCESSING)
            ->update([
                'status' => OutboxMessage::STATUS_PUBLISHED,
                'published_at' => now(),
                'locked_by' => null,
                'locked_at' => null,
                'last_error' => null,
            ]);

        if ($done === 0) {
            // Lost the claim (stale recovery gave the row to another publisher) → it may be published twice.
            Log::warning('Outbox row was published but the claim was lost; consumers will deduplicate', ['outbox_id' => $message->id, 'event_id' => $message->event_id]);
            Metrics::inc('nestlaravel_outbox_lost_claims_total', [], 1, 'Rows published after the claim was lost');
        }

        return $done === 1;
    }

    private function toKafkaMessage(OutboxMessage $message): KafkaMessage
    {
        $payload = is_array($message->payload) ? $message->payload : [];

        return new KafkaMessage(
            topic: $message->topic,
            key: $message->aggregate_id,
            value: $this->serializer->serialize($payload),
            headers: array_filter([
                'event_id' => $message->event_id,
                'event_type' => $message->event_type,
                'correlation_id' => $message->correlation_id,
                'tenant_id' => $message->tenant_id,
                'traceparent' => $payload['traceparent'] ?? null,
                'outbox_id' => (string) $message->id,
            ], static fn ($value) => $value !== null && $value !== ''),
            payload: $payload,
        );
    }

    private function handleFailure(OutboxMessage $message, Throwable $e): void
    {
        $error = mb_substr($e->getMessage(), 0, 1000);

        Log::error('Outbox publish failed', [
            'outbox_id' => $message->id,
            'event_id' => $message->event_id,
            'attempts' => $message->attempts,
            'error' => $error,
        ]);

        $terminal = $message->attempts >= $this->config->outboxMaxAttempts();

        $update = $terminal
            ? ['status' => OutboxMessage::STATUS_FAILED, 'locked_by' => null, 'locked_at' => null, 'last_error' => $error]
            : [
                'status' => OutboxMessage::STATUS_PENDING,
                'available_at' => now()->addSeconds($this->backoffSeconds((int) $message->attempts)),
                'locked_by' => null,
                'locked_at' => null,
                'last_error' => $error,
            ];

        OutboxMessage::query()->whereKey($message->id)->where('locked_by', $this->workerId)->update($update);

        if ($terminal) {
            $this->publishToDlq($message, $e);
            Metrics::inc('nestlaravel_outbox_failed_total', [], 1, 'Outbox rows that exhausted their retries');
        } else {
            Metrics::inc('nestlaravel_outbox_retries_total', [], 1, 'Outbox publish retries scheduled');
        }
    }

    private function publishToDlq(OutboxMessage $message, Throwable $e): void
    {
        try {
            $payload = is_array($message->payload) ? $message->payload : [];
            $payload['outbox_error'] = mb_substr($e->getMessage(), 0, 500);

            $this->producer->produce(new KafkaMessage(
                topic: $this->topics->dlq($message->topic),
                key: $message->aggregate_id,
                value: $this->serializer->serialize($payload),
                headers: ['event_id' => $message->event_id, 'event_type' => $message->event_type, 'dlq_reason' => mb_substr($e->getMessage(), 0, 300)],
                payload: $payload,
            ));
            $this->producer->flush();
        } catch (Throwable $dlqError) {
            // The row stays `failed` in the database (durable, inspectable with `outbox:status`, re-queueable).
            Log::critical('Failed to publish outbox message to DLQ; row remains in status=failed', [
                'outbox_id' => $message->id,
                'error' => $dlqError->getMessage(),
            ]);
        }
    }

    private function refreshGauges(): void
    {
        if (! Metrics::enabled() || time() - $this->lastGaugeAt < 10) {
            return;
        }

        $this->lastGaugeAt = time();

        try {
            $s = $this->snapshot();
            Metrics::gauge('nestlaravel_outbox_pending', $s['pending'], [], 'Outbox rows waiting to be published');
            Metrics::gauge('nestlaravel_outbox_processing', $s['processing'], [], 'Outbox rows currently claimed by a publisher');
            Metrics::gauge('nestlaravel_outbox_failed', $s['failed'], [], 'Outbox rows in terminal failed state');
            Metrics::gauge('nestlaravel_outbox_oldest_pending_age_seconds', $s['oldest_pending_age_seconds'] ?? 0, [], 'Age of the oldest pending row');
        } catch (Throwable $e) {
            Log::debug('Could not refresh outbox gauges', ['error' => $e->getMessage()]);
        }
    }
}
