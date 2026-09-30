<?php

namespace NestLaravel\Kafka;

use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Contracts\DomainEvent;
use NestLaravel\Kafka\Serializers\JsonEventSerializer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Writes domain events to the outbox table (preferably inside the caller's DB transaction).
 * Also dispatches Laravel events for in-process listeners.
 */
final class OutboxEventBus implements EventBus
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly KafkaTopic $topics,
        private readonly JsonEventSerializer $serializer = new JsonEventSerializer,
        private readonly bool $dispatchLaravelEvents = true,
    ) {}

    public function publish(DomainEvent $event): void
    {
        $write = function () use ($event): void {
            OutboxMessage::query()->create([
                'event_id' => $event->eventId(),
                'event_type' => $event->eventType(),
                'aggregate_id' => $event->aggregateId(),
                'aggregate_type' => $event->aggregateType(),
                'payload' => $this->serializer->toArray($event),
                'topic' => $this->topics->forEvent($event),
                'correlation_id' => $event->correlationId(),
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
            ]);
        };

        if (DB::transactionLevel() > 0) {
            $write();
        } else {
            DB::transaction($write);
        }

        if ($this->dispatchLaravelEvents) {
            $this->dispatcher->dispatch($event);
        }
    }

    public function publishMany(array $events): void
    {
        $write = function () use ($events): void {
            foreach ($events as $event) {
                OutboxMessage::query()->create([
                    'event_id' => $event->eventId(),
                    'event_type' => $event->eventType(),
                    'aggregate_id' => $event->aggregateId(),
                    'aggregate_type' => $event->aggregateType(),
                    'payload' => $this->serializer->toArray($event),
                    'topic' => $this->topics->forEvent($event),
                    'correlation_id' => $event->correlationId(),
                    'status' => OutboxMessage::STATUS_PENDING,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            $write();
        } else {
            DB::transaction($write);
        }

        if ($this->dispatchLaravelEvents) {
            foreach ($events as $event) {
                $this->dispatcher->dispatch($event);
            }
        }
    }
}
