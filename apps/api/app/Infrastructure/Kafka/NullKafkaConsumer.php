<?php

namespace App\Infrastructure\Kafka;

use Illuminate\Support\Facades\Log;

final class NullKafkaConsumer implements KafkaConsumer
{
    /** @var list<string> */
    private array $topics = [];

    public function subscribe(array $topics): void
    {
        $this->topics = array_values($topics);

        Log::debug('NullKafkaConsumer subscribed', ['topics' => $this->topics]);
    }

    public function consume(int $timeoutMs = 1000): ?KafkaMessage
    {
        usleep(min($timeoutMs, 100) * 1000);

        return null;
    }

    public function acknowledge(KafkaMessage $message): void
    {
        // no-op
    }

    public function close(): void
    {
        $this->topics = [];
    }
}
