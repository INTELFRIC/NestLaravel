<?php

namespace App\Messaging\Producers;

use App\Infrastructure\Kafka\KafkaMessage;
use App\Infrastructure\Kafka\KafkaProducer;
use App\Infrastructure\Kafka\KafkaTopic;
use App\Messaging\Contracts\DomainEvent;
use App\Messaging\Serializers\JsonEventSerializer;

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
