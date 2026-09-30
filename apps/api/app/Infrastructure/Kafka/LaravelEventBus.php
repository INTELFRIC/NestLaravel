<?php

namespace App\Infrastructure\Kafka;

use App\Core\Contracts\EventBus;
use App\Messaging\Contracts\DomainEvent;
use App\Messaging\Producers\DomainEventProducer;
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
