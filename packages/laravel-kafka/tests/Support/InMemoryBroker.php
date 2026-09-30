<?php

namespace NestLaravel\Kafka\Tests\Support;

use NestLaravel\Kafka\KafkaConsumer;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use RuntimeException;

/**
 * Broker whose log survives "process restarts": one append-only log, and every consumer group has its own committed
 * offset. A consumer created later resumes from the last COMMITTED offset of its group, exactly like Kafka.
 *
 * Fault injection: `down` (produce/flush fail), `duplicateDeliveries` (every message is delivered twice in a row, as after
 * a rebalance or a producer retry).
 */
final class InMemoryBroker
{
    /** @var list<KafkaMessage> */
    public array $log = [];

    /** @var array<string, int> committed offset per consumer group */
    private array $committed = [];

    public bool $down = false;

    public bool $duplicateDeliveries = false;

    public function committed(string $group = 'default'): int
    {
        return $this->committed[$group] ?? 0;
    }

    public function commit(string $group, int $next): void
    {
        $this->committed[$group] = $next;
    }

    /** @return list<string> event types in the order they were written to the log (DLQ copies excluded) */
    public function eventTypes(): array
    {
        $types = [];
        foreach ($this->log as $m) {
            if (! str_ends_with($m->topic, '.dlq') && is_array($decoded = json_decode($m->value, true))) {
                $types[] = $decoded['event_type'] ?? '?';
            }
        }

        return $types;
    }

    public function producer(): KafkaProducer
    {
        $broker = $this;

        return new class($broker) implements KafkaProducer
        {
            /** @var list<KafkaMessage> */
            private array $pending = [];

            public function __construct(private InMemoryBroker $broker) {}

            public function produce(KafkaMessage $message): void
            {
                if ($this->broker->down) {
                    throw new RuntimeException('broker unavailable');
                }
                $this->pending[] = $message;
            }

            public function produceMany(array $messages): void
            {
                array_map($this->produce(...), $messages);
            }

            public function flush(int $timeoutMs = 1000): void
            {
                if ($this->broker->down) {
                    $this->pending = [];
                    throw new RuntimeException('delivery failed');
                }
                array_push($this->broker->log, ...$this->pending);
                $this->pending = [];
            }
        };
    }

    public function consumer(string $group = 'default'): KafkaConsumer
    {
        $broker = $this;

        return new class($broker, $group) implements KafkaConsumer
        {
            private int $position;

            /** @var list<string> */
            private array $topics = [];

            private ?KafkaMessage $redeliver = null;

            public function __construct(private InMemoryBroker $broker, private string $group)
            {
                $this->position = $broker->committed($group);   // a (re)started consumer resumes from the last COMMITTED offset
            }

            public function subscribe(array $topics): void
            {
                $this->topics = $topics;
            }

            public function consume(int $timeoutMs = 1000): ?KafkaMessage
            {
                if ($this->redeliver !== null) {
                    [$m, $this->redeliver] = [$this->redeliver, null];

                    return $m;
                }

                // Like a real consumer: only messages of subscribed topics are delivered (other topics — e.g. the DLQ — are skipped).
                while (($m = $this->broker->log[$this->position] ?? null) !== null) {
                    $this->position++;
                    if ($this->topics === [] || in_array($m->topic, $this->topics, true)) {
                        $delivered = new KafkaMessage($m->topic, $m->key, $m->value, $m->headers, 0, $this->position - 1);
                        if ($this->broker->duplicateDeliveries) {
                            $this->redeliver = $delivered;
                        }

                        return $delivered;
                    }
                }

                return null;
            }

            public function acknowledge(KafkaMessage $message): void
            {
                $this->broker->commit($this->group, max($this->broker->committed($this->group), $message->offset + 1));
            }

            public function close(): void {}
        };
    }
}
