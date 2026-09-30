<?php

namespace NestLaravel\Kafka;

use NestLaravel\Kafka\Contracts\DomainEvent;

/**
 * Topic naming: an event goes to topics[<aggregate_type>] when that topic is
 * configured (see `nestlaravel generate kafka-topic`), otherwise to the
 * service default topic ("<service>.events").
 */
final class KafkaTopic
{
    public function __construct(
        private readonly KafkaConfig $config = new KafkaConfig,
    ) {}

    public function forEvent(DomainEvent $event): string
    {
        $aggregate = strtolower(str_replace('-', '_', $event->aggregateType()));
        $topics = $this->config->topics();

        return (string) ($topics[$aggregate] ?? $topics['default'] ?? 'domain.events');
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
