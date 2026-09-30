<?php

namespace App\Infrastructure\Kafka;

interface KafkaProducer
{
    public function produce(KafkaMessage $message): void;

    /**
     * @param  array<int, KafkaMessage>  $messages
     */
    public function produceMany(array $messages): void;

    public function flush(int $timeoutMs = 1000): void;
}
