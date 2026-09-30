<?php

namespace App\Infrastructure\Kafka;

use App\Messaging\Contracts\DomainEvent;

final class KafkaTopic
{
    public function __construct(
        private readonly KafkaConfig $config = new KafkaConfig,
    ) {}

    public function forEvent(DomainEvent $event): string
    {
        $aggregate = strtolower($event->aggregateType());

        $aliases = [
            'vehicle' => 'vehicle',
            'vehicles' => 'vehicle',
            'order' => 'order',
            'orders' => 'order',
            'payment' => 'payment',
            'payments' => 'payment',
            'notification' => 'notification',
            'notifications' => 'notification',
            'fraud' => 'fraud',
        ];

        $key = $aliases[$aggregate] ?? null;

        if ($key !== null) {
            return $this->config->topic($key);
        }

        return $this->config->topic('default');
    }

    public function dlq(string $topic): string
    {
        return $this->config->dlqTopic($topic);
    }

    public function resolve(string $name): string
    {
        return $this->config->topic($name, $name);
    }
}
