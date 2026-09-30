<?php

namespace NestLaravel\Kafka\Tests;

use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\KafkaTopic;
use NestLaravel\Kafka\CacheIdempotencyStore;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Serializers\JsonEventSerializer;
use RuntimeException;
use NestLaravel\Kafka\Tests\TestCase;

class ConsumerPipelineTest extends TestCase
{
    /** @var list<KafkaMessage> */
    private array $dlq = [];

    private bool $dlqFails = false;

    private function pipeline(int $maxVersion = 1): ConsumerPipeline
    {
        $config = new KafkaConfig([
            'consumer_pipeline' => ['max_retries' => 2, 'retry_backoff_ms' => 0, 'idempotency_ttl' => 60],
            'schema' => ['max_version' => $maxVersion],
        ]);

        $test = $this;
        $producer = new class($test) implements KafkaProducer
        {
            public function __construct(private ConsumerPipelineTest $test) {}

            public function produce(KafkaMessage $message): void
            {
                $this->test->capture($message);
            }

            public function produceMany(array $messages): void
            {
                array_map($this->produce(...), $messages);
            }

            public function flush(int $timeoutMs = 1000): void {}
        };

        return new ConsumerPipeline(new JsonEventSerializer, new CacheIdempotencyStore, $config, $producer, new KafkaTopic($config));
    }

    public function capture(KafkaMessage $message): void
    {
        if ($this->dlqFails) {
            throw new RuntimeException('broker down');
        }

        $this->dlq[] = $message;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function message(array $overrides = [], ?string $raw = null): KafkaMessage
    {
        $event = array_merge([
            'event_id' => 'evt-1',
            'event_type' => 'order.created',
            'aggregate_id' => '1',
            'aggregate_type' => 'order',
            'version' => 1,
            'payload' => ['a' => 1],
        ], $overrides);

        return new KafkaMessage('order.events', '1', $raw ?? json_encode($event));
    }

    public function test_successful_message_is_handled_once_even_if_redelivered(): void
    {
        $pipeline = $this->pipeline();
        $calls = 0;

        $this->assertTrue($pipeline->process($this->message(), function () use (&$calls): void { $calls++; }));
        $this->assertTrue($pipeline->process($this->message(), function () use (&$calls): void { $calls++; }));

        $this->assertSame(1, $calls, 'idempotency must suppress the duplicate delivery');
        $this->assertSame([], $this->dlq);
    }

    public function test_transient_failures_are_retried_then_dead_lettered(): void
    {
        $calls = 0;

        $ok = $this->pipeline()->process($this->message(), function () use (&$calls): void {
            $calls++;
            throw new RuntimeException('db timeout');
        });

        $this->assertFalse($ok);
        $this->assertSame(3, $calls, '1 attempt + 2 retries');
        $this->assertCount(1, $this->dlq);
        $this->assertSame('order.events.dlq', $this->dlq[0]->topic);
    }

    public function test_poison_message_goes_straight_to_dlq_without_retries(): void
    {
        $calls = 0;

        $ok = $this->pipeline()->process($this->message(raw: '{not json'), function () use (&$calls): void { $calls++; });

        $this->assertFalse($ok);
        $this->assertSame(0, $calls);
        $this->assertCount(1, $this->dlq);
    }

    public function test_events_with_a_newer_schema_version_are_dead_lettered(): void
    {
        $calls = 0;

        $ok = $this->pipeline(maxVersion: 1)->process($this->message(['version' => 2]), function () use (&$calls): void { $calls++; });

        $this->assertFalse($ok);
        $this->assertSame(0, $calls);
        $this->assertCount(1, $this->dlq);
    }

    public function test_a_failed_dlq_publish_is_not_swallowed_so_the_offset_is_not_committed(): void
    {
        $this->dlqFails = true;

        $this->expectException(RuntimeException::class);

        $this->pipeline()->process($this->message(), function (): void { throw new RuntimeException('boom'); });
    }
}
