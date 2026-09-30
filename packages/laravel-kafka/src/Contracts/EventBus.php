<?php

namespace NestLaravel\Kafka\Contracts;

use NestLaravel\Kafka\Contracts\DomainEvent;

interface EventBus
{
    public function publish(DomainEvent $event): void;

    /**
     * @param  array<int, DomainEvent>  $events
     */
    public function publishMany(array $events): void;
}
