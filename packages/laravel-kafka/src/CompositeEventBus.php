<?php

namespace NestLaravel\Kafka;

use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Contracts\DomainEvent;

/**
 * Publishes through multiple EventBus implementations (e.g. Laravel + Outbox).
 */
final class CompositeEventBus implements EventBus
{
    /** @var list<EventBus> */
    private array $buses;

    /**
     * @param  array<int, EventBus>  $buses
     */
    public function __construct(array $buses)
    {
        $this->buses = array_values($buses);
    }

    public function publish(DomainEvent $event): void
    {
        foreach ($this->buses as $bus) {
            $bus->publish($event);
        }
    }

    public function publishMany(array $events): void
    {
        foreach ($this->buses as $bus) {
            $bus->publishMany($events);
        }
    }
}
