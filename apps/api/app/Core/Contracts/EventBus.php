<?php

namespace App\Core\Contracts;

use App\Messaging\Contracts\DomainEvent;

interface EventBus
{
    public function publish(DomainEvent $event): void;

    /**
     * @param  array<int, DomainEvent>  $events
     */
    public function publishMany(array $events): void;
}
