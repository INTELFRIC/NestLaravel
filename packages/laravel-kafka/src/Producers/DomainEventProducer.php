<?php

namespace NestLaravel\Kafka\Producers;

use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\KafkaTopic;
use NestLaravel\Kafka\Contracts\DomainEvent;
use NestLaravel\Kafka\Serializers\JsonEventSerializer;

/**
 * Application-facing helper that maps domain events onto Kafka messages.
 */
final class DomainEventProducer
{
    public function __construct(
        private readonly KafkaProducer $producer,
        private readonly KafkaTopic $topics,
        private readonly JsonEventSerializer $serializer = new JsonEventSerializer,
    ) {}

    public function publish(DomainEvent $event, ?string $topic = null): void
    {
        $resolvedTopic = $topic ?? $this->topics->forEvent($event);
        $payload = $this->serializer->toArray($event);

        $message = KafkaMessage::fromPayload(
            topic: $resolvedTopic,
            key: $event->aggregateId(),
            payload: $payload,
            headers: array_filter([
                'event_id' => $event->eventId(),
                'event_type' => $event->eventType(),
                'correlation_id' => $event->correlationId(),
                'causation_id' => $event->causationId(),
            ], static fn ($value) => $value !== null),
        );

        $this->producer->produce($message);
    }

    /**
     * @param  array<int, DomainEvent>  $events
     */
    public function publishMany(array $events, ?string $topic = null): void
    {
        foreach ($events as $event) {
            $this->publish($event, $topic);
        }
    }
}
