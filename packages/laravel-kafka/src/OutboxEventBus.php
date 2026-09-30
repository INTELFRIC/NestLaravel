<?php

namespace NestLaravel\Kafka;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Contracts\DomainEvent;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Schema\EventSchemaRegistry;
use NestLaravel\Kafka\Serializers\JsonEventSerializer;

/**
 * Writes domain events to the outbox table INSIDE the caller's DB transaction (or its own, if none is open), so
 * "business change" and "event to publish" commit or roll back together. Events are validated against their
 * schema before anything is written; an invalid event throws and nothing is persisted.
 * Also dispatches Laravel events for in-process listeners (after the write).
 */
final class OutboxEventBus implements EventBus
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly KafkaTopic $topics,
        private readonly JsonEventSerializer $serializer = new JsonEventSerializer,
        private readonly bool $dispatchLaravelEvents = true,
        private readonly ?EventSchemaRegistry $schemas = null,
    ) {}

    public function publish(DomainEvent $event): void
    {
        $this->publishMany([$event]);
    }

    public function publishMany(array $events): void
    {
        foreach ($events as $event) {
            $this->schemas?->assertValidOutgoing($event);
        }

        $write = function () use ($events): void {
            foreach ($events as $event) {
                $envelope = $this->serializer->toArray($event);

                OutboxMessage::query()->create([
                    'event_id' => $event->eventId(),
                    'event_type' => $event->eventType(),
                    'aggregate_id' => $event->aggregateId(),
                    'aggregate_type' => $event->aggregateType(),
                    'payload' => $envelope,
                    'topic' => $this->topics->forEvent($event),
                    'correlation_id' => $event->correlationId(),
                    'tenant_id' => isset($envelope['tenant_id']) ? (string) $envelope['tenant_id'] : null,
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

        Metrics::inc('nestlaravel_events_produced_total', ['via' => 'outbox'], count($events), 'Events written for publication');

        if ($this->dispatchLaravelEvents) {
            // In-process listeners only see events whose transaction actually committed.
            DB::afterCommit(function () use ($events): void {
                foreach ($events as $event) {
                    $this->dispatcher->dispatch($event);
                }
            });
        }
    }
}
