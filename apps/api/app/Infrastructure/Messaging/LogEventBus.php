<?php

namespace App\Infrastructure\Messaging;

use App\Core\Contracts\EventBus;
use App\Messaging\Contracts\DomainEvent;
use Illuminate\Support\Facades\Log;

/**
 * Temporary EventBus used until Infrastructure Kafka/Outbox bindings replace it.
 */
final class LogEventBus implements EventBus
{
    public function publish(DomainEvent $event): void
    {
        Log::info('domain_event.published', $event->toArray());
    }

    public function publishMany(array $events): void
    {
        foreach ($events as $event) {
            $this->publish($event);
        }
    }
}
