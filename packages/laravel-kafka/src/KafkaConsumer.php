<?php

namespace NestLaravel\Kafka;

interface KafkaConsumer
{
    /**
     * Subscribe to one or more topics.
     *
     * @param  list<string>  $topics
     */
    public function subscribe(array $topics): void;

    /**
     * Consume a single message or return null when none available.
     */
    public function consume(int $timeoutMs = 1000): ?KafkaMessage;

    /**
     * Acknowledge successful processing of the last consumed message.
     */
    public function acknowledge(KafkaMessage $message): void;

    /**
     * Close the consumer and release resources.
     */
    public function close(): void;
}
