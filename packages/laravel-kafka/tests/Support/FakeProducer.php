<?php

namespace NestLaravel\Kafka\Tests\Support;

use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use RuntimeException;

/** In-memory producer with switchable failure modes (broker down, delivery failure). */
class FakeProducer implements KafkaProducer
{
    /** @var list<KafkaMessage> produced (not necessarily delivered) */
    public array $produced = [];

    /** @var list<KafkaMessage> delivered = produced before a successful flush() */
    public array $delivered = [];

    public bool $brokerDown = false;

    public bool $failFlush = false;

    public int $flushes = 0;

    public function produce(KafkaMessage $message): void
    {
        if ($this->brokerDown) {
            throw new RuntimeException('broker unavailable');
        }

        $this->produced[] = $message;
    }

    public function produceMany(array $messages): void
    {
        foreach ($messages as $message) {
            $this->produce($message);
        }
    }

    public function flush(int $timeoutMs = 1000): void
    {
        $this->flushes++;

        if ($this->failFlush || $this->brokerDown) {
            $this->produced = [];

            throw new RuntimeException('delivery failed');
        }

        $this->delivered = array_merge($this->delivered, $this->produced);
        $this->produced = [];
    }

    /** @return list<string> delivered topics */
    public function topics(): array
    {
        return array_map(static fn (KafkaMessage $m) => $m->topic, $this->delivered);
    }
}
