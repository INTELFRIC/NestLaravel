<?php

namespace App\Infrastructure\Kafka\Outbox;

use App\Infrastructure\Kafka\KafkaConfig;
use App\Infrastructure\Kafka\KafkaMessage;
use App\Infrastructure\Kafka\KafkaProducer;
use App\Infrastructure\Kafka\KafkaSerializer;
use App\Infrastructure\Kafka\KafkaTopic;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ships outbox rows to Kafka. A row is marked published ONLY after the producer
 * confirmed broker delivery (flush) — never merely after an async produce().
 * Run a single publisher per service (rows are not claimed across workers).
 */
final class OutboxPublisher
{
    public function __construct(
        private readonly KafkaProducer $producer,
        private readonly KafkaConfig $config,
        private readonly KafkaTopic $topics,
        private readonly KafkaSerializer $serializer = new KafkaSerializer,
    ) {}

    public function publishPending(?int $limit = null): int
    {
        $limit ??= $this->config->outboxBatchSize();

        $messages = OutboxMessage::query()
            ->where('status', OutboxMessage::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        /** @var list<OutboxMessage> $sent */
        $sent = [];

        foreach ($messages as $message) {
            $message->attempts = (int) $message->attempts + 1;
            $message->save();

            try {
                $this->producer->produce($this->toKafkaMessage($message));
                $sent[] = $message;
            } catch (Throwable $e) {
                $this->handleFailure($message, $e);
            }
        }

        if ($sent === []) {
            return 0;
        }

        try {
            $this->producer->flush();
        } catch (Throwable $e) {
            foreach ($sent as $message) {
                $this->handleFailure($message, $e);
            }

            return 0;
        }

        foreach ($sent as $message) {
            $message->markPublished();
        }

        return count($sent);
    }

    public function publishOne(OutboxMessage $message): bool
    {
        $message->attempts = (int) $message->attempts + 1;
        $message->save();

        try {
            $this->producer->produce($this->toKafkaMessage($message));
            $this->producer->flush();
            $message->markPublished();

            return true;
        } catch (Throwable $e) {
            $this->handleFailure($message, $e);

            return false;
        }
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
                'outbox_id' => (string) $message->id,
            ], static fn ($value) => $value !== null && $value !== ''),
            payload: $payload,
        );
    }

    private function handleFailure(OutboxMessage $message, Throwable $e): void
    {
        Log::error('Outbox publish failed', [
            'outbox_id' => $message->id,
            'event_id' => $message->event_id,
            'attempts' => $message->attempts,
            'error' => $e->getMessage(),
        ]);

        if ($message->attempts >= $this->config->outboxMaxAttempts()) {
            $this->publishToDlq($message, $e);
            $message->markFailed();
        } else {
            $message->scheduleRetry($this->config->outboxRetryDelaySeconds());
        }
    }

    private function publishToDlq(OutboxMessage $message, Throwable $e): void
    {
        try {
            $payload = is_array($message->payload) ? $message->payload : [];
            $payload['outbox_error'] = $e->getMessage();

            $dlqMessage = new KafkaMessage(
                topic: $this->topics->dlq($message->topic),
                key: $message->aggregate_id,
                value: $this->serializer->serialize($payload),
                headers: [
                    'event_id' => $message->event_id,
                    'event_type' => $message->event_type,
                    'dlq_reason' => $e->getMessage(),
                ],
                payload: $payload,
            );

            $this->producer->produce($dlqMessage);
            $this->producer->flush();
        } catch (Throwable $dlqError) {
            Log::critical('Failed to publish outbox message to DLQ', [
                'outbox_id' => $message->id,
                'error' => $dlqError->getMessage(),
            ]);
        }
    }
}
