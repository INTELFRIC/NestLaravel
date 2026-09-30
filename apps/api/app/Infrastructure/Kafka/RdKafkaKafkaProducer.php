<?php

namespace App\Infrastructure\Kafka;

use RdKafka\Conf;
use RdKafka\Producer;
use RuntimeException;

/**
 * Producer backed by ext-rdkafka.
 *
 * Delivery guarantees: acks=all + idempotence (no broker-side duplicates on retry).
 * produce() is asynchronous; delivery is only confirmed by flush(), which throws
 * if ANY message since the previous flush failed. Callers that mark work as
 * "published" (the Outbox) MUST call flush() first.
 */
final class RdKafkaKafkaProducer implements KafkaProducer
{
    private mixed $producer = null;

    /** @var array<string, mixed> */
    private array $topics = [];

    /** @var list<string> */
    private array $deliveryErrors = [];

    public function __construct(
        private readonly KafkaConfig $config,
    ) {
        if (! extension_loaded('rdkafka')) {
            throw new RuntimeException('ext-rdkafka is required for RdKafkaKafkaProducer.');
        }
    }

    public function produce(KafkaMessage $message): void
    {
        $topic = $this->topic($message->topic);

        $headers = [];
        foreach ($message->headers as $name => $value) {
            $headers[(string) $name] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        $topic->producev(
            $message->partition ?? RD_KAFKA_PARTITION_UA,
            0,
            $message->value,
            $message->key,
            $headers,
        );

        $this->producer()->poll(0);
    }

    public function produceMany(array $messages): void
    {
        foreach ($messages as $message) {
            $this->produce($message);
        }
    }

    public function flush(int $timeoutMs = 0): void
    {
        $timeoutMs = $timeoutMs > 0 ? $timeoutMs : $this->config->flushTimeoutMs();
        $result = RD_KAFKA_RESP_ERR__TIMED_OUT;

        // librdkafka may need several flush rounds while brokers respond.
        for ($i = 0; $i < 3 && $result !== RD_KAFKA_RESP_ERR_NO_ERROR; $i++) {
            $result = $this->producer()->flush($timeoutMs);
        }

        $errors = $this->deliveryErrors;
        $this->deliveryErrors = [];

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException('Kafka flush timed out with code '.$result.'; messages may be undelivered.');
        }

        if ($errors !== []) {
            throw new RuntimeException('Kafka delivery failed: '.implode('; ', array_slice($errors, 0, 3)));
        }
    }

    private function producer(): mixed
    {
        if ($this->producer !== null) {
            return $this->producer;
        }

        $conf = new Conf;

        foreach ($this->config->producerSettings() as $key => $value) {
            $conf->set($key, $value);
        }

        $conf->setDrMsgCb(function ($kafka, $message): void {
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->deliveryErrors[] = rd_kafka_err2str($message->err);
            }
        });

        $this->producer = new Producer($conf);

        return $this->producer;
    }

    private function topic(string $name): mixed
    {
        if (! isset($this->topics[$name])) {
            $this->topics[$name] = $this->producer()->newTopic($name);
        }

        return $this->topics[$name];
    }
}
