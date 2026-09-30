<?php

namespace NestLaravel\Kafka;

use Illuminate\Support\Facades\Log;

final class NullKafkaProducer implements KafkaProducer
{
    public function produce(KafkaMessage $message): void
    {
        Log::debug('NullKafkaProducer dropped message', [
            'topic' => $message->topic,
            'key' => $message->key,
        ]);
    }

    public function produceMany(array $messages): void
    {
        foreach ($messages as $message) {
            $this->produce($message);
        }
    }

    public function flush(int $timeoutMs = 1000): void
    {
        // no-op
    }
}
