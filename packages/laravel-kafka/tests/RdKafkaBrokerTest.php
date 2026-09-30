<?php

namespace NestLaravel\Kafka\Tests;

use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaConsumer;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\RdKafkaKafkaConsumer;
use NestLaravel\Kafka\RdKafkaKafkaProducer;

/**
 * Real-broker integration test (producer → broker → consumer → pipeline → DLQ).
 * Skipped unless ext-rdkafka is loaded and KAFKA_INTEGRATION_BROKERS is set, e.g.
 *
 *   KAFKA_INTEGRATION_BROKERS=127.0.0.1:9092 vendor/bin/phpunit --filter RdKafkaBrokerTest
 *
 * CI runs it against an apache/kafka KRaft service container.
 */
class RdKafkaBrokerTest extends TestCase
{
    private string $brokers;

    protected function setUp(): void
    {
        parent::setUp();

        $brokers = getenv('KAFKA_INTEGRATION_BROKERS');

        if (! extension_loaded('rdkafka') || ! $brokers) {
            $this->markTestSkipped('Needs ext-rdkafka and KAFKA_INTEGRATION_BROKERS.');
        }

        $this->brokers = $brokers;
    }

    private function config(string $group): KafkaConfig
    {
        return new KafkaConfig([
            'enabled' => true,
            'brokers' => $this->brokers,
            'client_id' => 'integration-test',
            'group_id' => $group,
            'security' => ['protocol' => 'plaintext'],
            'consumer_options' => ['auto_offset_reset' => 'earliest', 'session_timeout_ms' => 10000],
            'consumer_pipeline' => ['max_retries' => 1, 'retry_backoff_ms' => 0, 'idempotency_ttl' => 60],
            'schema' => ['max_version' => 1],
        ]);
    }

    private function event(string $id): array
    {
        return [
            'event_id' => $id,
            'event_type' => 'it.thing.created',
            'aggregate_id' => 'agg-1',
            'aggregate_type' => 'thing',
            'version' => 1,
            'payload' => ['n' => $id],
        ];
    }

    /**
     * @return list<KafkaMessage>
     */
    private function drain(KafkaConsumer $consumer, int $expected, int $seconds = 45): array
    {
        $got = [];
        $deadline = time() + $seconds;

        while (count($got) < $expected && time() < $deadline) {
            $message = $consumer->consume(1000);

            if ($message !== null) {
                $got[] = $message;
            }
        }

        return $got;
    }

    public function test_events_round_trip_through_a_real_broker_with_pipeline_and_dlq(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $topic = "it.events.{$suffix}";
        $group = "it-group-{$suffix}";
        $config = $this->config($group);

        // --- produce: 3 good events + 1 poison message; delivery confirmed by flush() ---------------------
        $producer = new RdKafkaKafkaProducer($config);
        $this->assertInstanceOf(KafkaProducer::class, $producer);

        foreach (['e1', 'e2', 'e3'] as $id) {
            $producer->produce(KafkaMessage::fromPayload($topic, 'agg-1', $this->event($id)));
        }
        $producer->produce(new KafkaMessage($topic, 'agg-1', '{not json'));
        $producer->flush();

        // --- consume through the pipeline; commit only after handled/dead-lettered ------------------------
        $consumer = new RdKafkaKafkaConsumer($config);
        $consumer->subscribe([$topic]);

        // A pipeline bound to THIS config (brokers) so the DLQ goes to the real broker.
        $pipeline = new ConsumerPipeline(
            new \NestLaravel\Kafka\Serializers\JsonEventSerializer,
            new \NestLaravel\Kafka\CacheIdempotencyStore,
            $config,
            $producer,
            new \NestLaravel\Kafka\KafkaTopic($config),
        );

        $handled = [];
        foreach ($this->drain($consumer, 4) as $message) {
            $ok = $pipeline->process($message, function (array $event) use (&$handled): void {
                $handled[] = $event['event_id'];
            });
            $consumer->acknowledge($message);
            $this->assertSame($message->value !== '{not json', $ok);
        }

        sort($handled);
        $this->assertSame(['e1', 'e2', 'e3'], $handled, 'all valid events were handled exactly once');
        $consumer->close();

        // --- the poison message is on <topic>.dlq -----------------------------------------------------------
        $dlqConsumer = new RdKafkaKafkaConsumer($this->config("it-dlq-{$suffix}"));
        $dlqConsumer->subscribe(["{$topic}.dlq"]);
        $dead = $this->drain($dlqConsumer, 1);
        $dlqConsumer->close();

        $this->assertCount(1, $dead, 'poison message was dead-lettered');
        $this->assertSame($topic, $dead[0]->headers['original_topic'] ?? null);

        // --- offsets were committed: a new consumer in the same group sees nothing ---------------------------
        $again = new RdKafkaKafkaConsumer($config);
        $again->subscribe([$topic]);
        $this->assertSame([], $this->drain($again, 1, 8), 'committed offsets: no redelivery');
        $again->close();
    }
}
