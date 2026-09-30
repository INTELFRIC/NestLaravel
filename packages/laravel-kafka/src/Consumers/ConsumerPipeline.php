<?php

namespace NestLaravel\Kafka\Consumers;

use NestLaravel\Kafka\Contracts\IdempotencyStore;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\KafkaTopic;
use NestLaravel\Kafka\Serializers\JsonEventSerializer;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Deserialize → validate → idempotency check → handler → ack.
 * On failure: retry then publish to DLQ.
 */
final class ConsumerPipeline
{
    public function __construct(
        private readonly JsonEventSerializer $serializer,
        private readonly IdempotencyStore $idempotency,
        private readonly KafkaConfig $config,
        private readonly KafkaProducer $producer,
        private readonly KafkaTopic $topics,
    ) {}

    /**
     * @param  callable(array<string, mixed>): void|MessageHandler  $handler
     */
    public function process(KafkaMessage $message, callable|MessageHandler $handler): bool
    {
        $attempts = 0;
        $maxRetries = max(0, $this->config->consumerMaxRetries());
        $lastException = null;

        while ($attempts <= $maxRetries) {
            $attempts++;

            try {
                $event = $this->deserializeAndValidate($message);
                $eventId = (string) $event['event_id'];
                $idempotencyKey = $this->idempotencyKey($message->topic, $eventId);

                if ($this->idempotency->has($idempotencyKey)) {
                    Log::info('Skipping already processed event', [
                        'event_id' => $eventId,
                        'topic' => $message->topic,
                    ]);

                    return true;
                }

                $this->invokeHandler($handler, $event);

                $this->idempotency->remember($idempotencyKey, $this->config->idempotencyTtl());

                return true;
            } catch (InvalidArgumentException $e) {
                // Poison message (malformed / incompatible schema): retrying can never succeed.
                $lastException = $e;
                Log::warning('Consumer received an invalid event; dead-lettering', [
                    'topic' => $message->topic,
                    'error' => $e->getMessage(),
                ]);

                break;
            } catch (Throwable $e) {
                $lastException = $e;

                Log::warning('Consumer pipeline attempt failed', [
                    'topic' => $message->topic,
                    'attempt' => $attempts,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts <= $maxRetries) {
                    $this->backoff($attempts);
                }
            }
        }

        $this->sendToDlq($message, $lastException);

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function deserializeAndValidate(KafkaMessage $message): array
    {
        $event = $message->payload ?? $this->serializer->deserialize($message->value);
        $this->serializer->validate($event);

        $version = $event['version'] ?? 1;

        if (! is_int($version) || $version < 1) {
            throw new InvalidArgumentException('Domain event [version] must be a positive integer.');
        }

        if ($version > $this->config->maxEventVersion()) {
            throw new InvalidArgumentException(
                "Domain event schema version {$version} is newer than supported ({$this->config->maxEventVersion()}).",
            );
        }

        return $event;
    }

    /**
     * @param  callable(array<string, mixed>): void|MessageHandler  $handler
     * @param  array<string, mixed>  $event
     */
    private function invokeHandler(callable|MessageHandler $handler, array $event): void
    {
        if ($handler instanceof MessageHandler) {
            $handler->handle($event);

            return;
        }

        $handler($event);
    }

    private function backoff(int $attempt): void
    {
        $base = $this->config->retryBackoffMs();

        if ($base > 0) {
            usleep(min($base * (2 ** ($attempt - 1)), 5000) * 1000);
        }
    }

    private function idempotencyKey(string $topic, string $eventId): string
    {
        return "kafka:{$topic}:{$eventId}";
    }

    private function sendToDlq(KafkaMessage $message, ?Throwable $exception): void
    {
        $dlqTopic = $this->topics->dlq($message->topic);

        try {
            $payload = $message->payload;
            if ($payload === null) {
                try {
                    $payload = $this->serializer->deserialize($message->value);
                } catch (InvalidArgumentException) {
                    $payload = ['raw' => $message->value];
                }
            }

            $payload['consumer_error'] = $exception?->getMessage();
            $payload['original_topic'] = $message->topic;

            $dlqMessage = KafkaMessage::fromPayload(
                topic: $dlqTopic,
                key: $message->key,
                payload: $payload,
                headers: array_merge($message->headers, [
                    'dlq_reason' => $exception?->getMessage() ?? 'unknown',
                    'original_topic' => $message->topic,
                ]),
            );

            $this->producer->produce($dlqMessage);
            $this->producer->flush();

            Log::error('Message sent to DLQ', [
                'topic' => $message->topic,
                'dlq_topic' => $dlqTopic,
                'key' => $message->key,
                'error' => $exception?->getMessage(),
            ]);
        } catch (Throwable $e) {
            Log::critical('Failed to publish consumer message to DLQ', [
                'topic' => $message->topic,
                'error' => $e->getMessage(),
                'original_error' => $exception?->getMessage(),
            ]);

            // Do NOT swallow: the caller must not acknowledge (commit the offset of)
            // a message that was neither processed nor dead-lettered.
            throw $e;
        }
    }
}
