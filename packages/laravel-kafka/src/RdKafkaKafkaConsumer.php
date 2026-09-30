<?php

namespace NestLaravel\Kafka;

use RuntimeException;

/**
 * Consumer backed by ext-rdkafka (high-level, consumer-group based).
 *
 * Offsets are stored and committed only in acknowledge(), i.e. AFTER the
 * consumer pipeline handled (or dead-lettered) the message → at-least-once.
 * Group rebalances are handled by librdkafka; close() commits and leaves the
 * group cleanly so partitions are reassigned immediately on graceful shutdown.
 */
final class RdKafkaKafkaConsumer implements KafkaConsumer
{
    private ?\RdKafka\KafkaConsumer $consumer = null;

    private readonly KafkaSerializer $serializer;

    public function __construct(
        private readonly KafkaConfig $config,
        ?KafkaSerializer $serializer = null,
    ) {
        if (! extension_loaded('rdkafka')) {
            throw new RuntimeException('ext-rdkafka is required for RdKafkaKafkaConsumer.');
        }

        $this->serializer = $serializer ?? new KafkaSerializer;
    }

    public function subscribe(array $topics): void
    {
        $this->consumer()->subscribe(array_values($topics));
    }

    public function consume(int $timeoutMs = 1000): ?KafkaMessage
    {
        $message = $this->consumer()->consume($timeoutMs);

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                break;
            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                return null;
            default:
                throw new RuntimeException('Kafka consume error: '.$message->errstr());
        }

        $headers = [];
        foreach ((array) ($message->headers ?? []) as $name => $value) {
            $headers[(string) $name] = $value;
        }

        return new KafkaMessage(
            topic: (string) $message->topic_name,
            key: (string) ($message->key ?? ''),
            value: (string) $message->payload,
            headers: $headers,
            partition: (int) $message->partition,
            offset: (int) $message->offset,
            // Payload stays null: undecodable (poison) messages must reach the pipeline
            // and be dead-lettered, not crash the consumer here.
        );
    }

    public function acknowledge(KafkaMessage $message): void
    {
        if ($message->partition === null || $message->offset === null) {
            return;
        }

        $topic = $this->consumer()->newTopic($message->topic);
        $topic->offsetStore($message->partition, $message->offset);
        $this->consumer()->commit();
    }

    public function close(): void
    {
        if ($this->consumer !== null) {
            $this->consumer->close();
            $this->consumer = null;
        }
    }

    private function consumer(): \RdKafka\KafkaConsumer
    {
        if ($this->consumer !== null) {
            return $this->consumer;
        }

        $conf = new \RdKafka\Conf;

        foreach ($this->config->consumerSettings() as $key => $value) {
            $conf->set($key, $value);
        }

        return $this->consumer = new \RdKafka\KafkaConsumer($conf);
    }
}
