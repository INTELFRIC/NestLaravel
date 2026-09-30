<?php

namespace NestLaravel\Kafka;

use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Contracts\DomainEvent;
use NestLaravel\Kafka\Producers\DomainEventProducer;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Dispatches in-process Laravel events and publishes to Kafka (direct producer).
 */
final class LaravelEventBus implements EventBus
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly DomainEventProducer $producer,
        private readonly bool $publishToKafka = true,
    ) {}

    public function publish(DomainEvent $event): void
    {
        $this->dispatcher->dispatch($event);

        if ($this->publishToKafka) {
            $this->producer->publish($event);
        }
    }

    public function publishMany(array $events): void
    {
        foreach ($events as $event) {
            $this->publish($event);
        }
    }
}
