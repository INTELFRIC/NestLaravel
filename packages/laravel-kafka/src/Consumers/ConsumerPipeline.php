<?php

namespace NestLaravel\Kafka\Consumers;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NestLaravel\Kafka\Contracts\IdempotencyStore;
use NestLaravel\Kafka\Exceptions\InvalidEventException;
use NestLaravel\Kafka\Exceptions\NonRetryable;
use NestLaravel\Kafka\Inbox\EventInbox;
use NestLaravel\Kafka\Inbox\InboxResult;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\KafkaTopic;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Observability\TraceContext;
use NestLaravel\Kafka\Observability\Tracer;
use NestLaravel\Kafka\Schema\EventSchemaRegistry;
use NestLaravel\Kafka\Serializers\JsonEventSerializer;
use Throwable;

/**
 * Receive → validate envelope + version + schema → (inbox | legacy idempotency) → handler → return.
 *
 *   ✔ returns true  : handled, or recognised as a duplicate  → caller may ACK (commit the offset)
 *   ✔ returns false : retries exhausted / poison / NonRetryable → the message is safely on <topic>.dlq → caller may ACK
 *   ✘ throws        : could not even dead-letter it            → caller must NOT ACK (message is redelivered)
 *
 * The offset is therefore never committed for a message that was neither processed nor durably parked.
 *
 * With `kafka.inbox.enabled` the handler runs inside a DB transaction together with the dedup record (see
 * EventInbox), which makes redelivery after a crash between "business commit" and "offset commit" harmless.
 * Without it, the 1.0 cache-based check is used (weaker: not atomic with the business transaction).
 */
final class ConsumerPipeline
{
    public function __construct(
        private readonly JsonEventSerializer $serializer,
        private readonly IdempotencyStore $idempotency,
        private readonly KafkaConfig $config,
        private readonly KafkaProducer $producer,
        private readonly KafkaTopic $topics,
        private readonly ?EventInbox $inbox = null,
        private readonly ?EventSchemaRegistry $schemas = null,
    ) {}

    /**
     * @param  callable(array<string, mixed>): void|MessageHandler  $handler
     */
    public function process(KafkaMessage $message, callable|MessageHandler $handler): bool
    {
        $started = hrtime(true);
        $attempts = 0;
        $maxRetries = max(0, $this->config->consumerMaxRetries());
        $lastException = null;
        $labels = ['topic' => $message->topic];

        try {
            while ($attempts <= $maxRetries) {
                $attempts++;

                try {
                    $event = $this->deserializeAndValidate($message);
                    $this->enterContext($event, $message);

                    $result = Tracer::span(
                        'kafka.consume '.$event['event_type'],
                        ['messaging.system' => 'kafka', 'messaging.destination' => $message->topic, 'event.id' => (string) $event['event_id']],
                        fn () => $this->handleOnce($event, $message, $handler),
                        'consumer',
                    );

                    Metrics::inc('nestlaravel_kafka_consumed_total', $labels + ['result' => $result], help: 'Kafka messages consumed by result');
                    Metrics::observe('nestlaravel_kafka_processing_seconds', (hrtime(true) - $started) / 1e9, $labels, 'Time from receive to handled');

                    return true;
                } catch (InvalidArgumentException $e) {
                    // Poison message (malformed / invalid schema / unsupported version): retrying can never succeed.
                    $lastException = $e;
                    Log::warning('Consumer received an invalid event; dead-lettering', ['topic' => $message->topic, 'error' => $e->getMessage()]);

                    break;
                } catch (Throwable $e) {
                    $lastException = $e;

                    if ($e instanceof NonRetryable) {
                        Log::warning('Handler failed with a non-retryable error; dead-lettering', ['topic' => $message->topic, 'error' => $e->getMessage()]);

                        break;
                    }

                    Log::warning('Consumer pipeline attempt failed', [
                        'topic' => $message->topic,
                        'attempt' => $attempts,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                        'exception' => $e,
                    ]);

                    if ($attempts <= $maxRetries) {
                        Metrics::inc('nestlaravel_kafka_retries_total', $labels, help: 'Handler retries');
                        $this->backoff($attempts);
                    }
                }
            }

            $this->sendToDlq($message, $lastException);
            Metrics::inc('nestlaravel_kafka_consumed_total', $labels + ['result' => 'dead_lettered'], help: 'Kafka messages consumed by result');
            Metrics::inc('nestlaravel_kafka_dlq_total', $labels, help: 'Messages sent to the dead-letter topic');

            return false;
        } finally {
            LogContext::forget('event_id', 'correlation_id', 'tenant_id', 'causation_id', 'trace_id', 'span_id');
            TraceContext::activate(null);
            Tracer::flush();
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  callable(array<string, mixed>): void|MessageHandler  $handler
     * @return 'processed'|'duplicate'
     */
    private function handleOnce(array $event, KafkaMessage $message, callable|MessageHandler $handler): string
    {
        $eventId = (string) $event['event_id'];

        if ($this->inbox !== null && $this->config->inboxEnabled()) {
            $result = $this->inbox->process(
                $eventId,
                fn () => $this->invokeHandler($handler, $event),
                $this->config->groupId(),
                [
                    'event_type' => (string) $event['event_type'],
                    'correlation_id' => isset($event['correlation_id']) ? (string) $event['correlation_id'] : null,
                    'tenant_id' => isset($event['tenant_id']) ? (string) $event['tenant_id'] : null,
                ],
            );

            return $result === InboxResult::Duplicate ? 'duplicate' : 'processed';
        }

        // Legacy (1.0) path: cache-based, not atomic with the business transaction.
        $key = "kafka:{$message->topic}:{$eventId}";

        if ($this->idempotency->has($key)) {
            Log::info('Skipping already processed event', ['event_id' => $eventId, 'topic' => $message->topic]);

            return 'duplicate';
        }

        $this->invokeHandler($handler, $event);
        $this->idempotency->remember($key, $this->config->idempotencyTtl());

        return 'processed';
    }

    /** @param array<string, mixed> $event */
    private function enterContext(array $event, KafkaMessage $message): void
    {
        LogContext::set(array_filter([
            'event_id' => (string) $event['event_id'],
            'correlation_id' => $event['correlation_id'] ?? null,
            'causation_id' => $event['causation_id'] ?? null,
            'tenant_id' => isset($event['tenant_id']) ? (string) $event['tenant_id'] : null,
        ], static fn ($v) => $v !== null && $v !== ''));

        $traceparent = $message->headers['traceparent'] ?? $event['traceparent'] ?? null;
        TraceContext::activate(TraceContext::continueFrom(is_string($traceparent) ? $traceparent : null));
    }

    /**
     * @return array<string, mixed>
     */
    private function deserializeAndValidate(KafkaMessage $message): array
    {
        $event = $message->payload ?? $this->serializer->deserialize($message->value);
        $this->serializer->validate($event);

        $version = $event['event_version'] ?? $event['version'] ?? 1;

        if ($version > $this->config->maxEventVersion()) {
            throw new InvalidEventException(
                "Domain event schema version {$version} is newer than supported ({$this->config->maxEventVersion()}).",
            );
        }

        $this->schemas?->assertValidIncoming($event);

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
                headers: array_merge($message->headers, array_filter([
                    'dlq_reason' => $exception?->getMessage() ?? 'unknown',
                    'dlq_exception' => $exception ? $exception::class : null,
                    'dlq_at' => now()->toIso8601String(),
                    'original_topic' => $message->topic,
                    'original_partition' => $message->partition !== null ? (string) $message->partition : null,
                    'original_offset' => $message->offset !== null ? (string) $message->offset : null,
                ], static fn ($v) => $v !== null)),
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
