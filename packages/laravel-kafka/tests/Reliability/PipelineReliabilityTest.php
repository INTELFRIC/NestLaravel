<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Exceptions\NonRetryable;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use NestLaravel\Kafka\Tests\Support\FakeProducer;
use RuntimeException;

final class OrderAlreadyCancelled extends \DomainException implements NonRetryable
{
}

class PipelineReliabilityTest extends ReliabilityTestCase
{
    private FakeProducer $dlq;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kafka.inbox.enabled' => true, 'kafka.group_id' => 'payments-service']);
        $this->dlq = new FakeProducer;
        $this->app->instance(KafkaProducer::class, $this->dlq);
        $this->app->forgetInstance(ConsumerPipeline::class);
    }

    private function pipeline(): ConsumerPipeline
    {
        return $this->app->make(ConsumerPipeline::class);
    }

    private function message(string $id = 'evt-1', array $overrides = []): KafkaMessage
    {
        return new KafkaMessage('orders.events', 'o-1', json_encode(array_merge([
            'event_id' => $id,
            'event_type' => 'orders.order.created',
            'event_version' => 1,
            'aggregate_id' => 'o-1',
            'aggregate_type' => 'order',
            'occurred_at' => now()->toIso8601String(),
            'correlation_id' => 'corr-123',
            'payload' => ['order_id' => 'o-1'],
        ], $overrides)), partition: 0, offset: 7);
    }

    private function charge(): callable
    {
        return static fn (array $e) => DB::table('payments')->insert(['order_id' => $e['payload']['order_id'], 'amount' => 100]);
    }

    public function test_redelivery_after_crash_between_db_commit_and_offset_commit_charges_once(): void
    {
        // Delivery 1: business + inbox commit; the consumer "dies" before acknowledging (we simply ignore the return).
        $this->assertTrue($this->pipeline()->process($this->message(), $this->charge()));

        // Delivery 2: Kafka redelivers the same event.
        $this->assertTrue($this->pipeline()->process($this->message(), $this->charge()), 'duplicate is ACKed');

        $this->assertSame(1, DB::table('payments')->count(), 'no duplicate payment');
        $this->assertSame([], $this->dlq->delivered, 'a duplicate is not an error');
        $this->assertSame(1, Metrics::value('nestlaravel_kafka_consumed_total', ['topic' => 'orders.events', 'result' => 'duplicate']));
    }

    public function test_transient_handler_failure_is_retried_and_succeeds_once(): void
    {
        $calls = 0;
        $handler = function (array $e) use (&$calls) {
            $calls++;
            DB::table('payments')->insert(['order_id' => 'o-1', 'amount' => 100]);
            if ($calls < 3) {
                throw new RuntimeException('temporary');
            }
        };

        $this->assertTrue($this->pipeline()->process($this->message(), $handler));
        $this->assertSame(3, $calls);
        $this->assertSame(1, DB::table('payments')->count(), 'failed attempts rolled back; only the successful one persisted');
        $this->assertSame(2, Metrics::value('nestlaravel_kafka_retries_total', ['topic' => 'orders.events']));
    }

    public function test_retries_exhausted_goes_to_dlq_and_leaves_no_inbox_record(): void
    {
        $ok = $this->pipeline()->process($this->message(), function () {
            throw new RuntimeException('always failing');
        });

        $this->assertFalse($ok);
        $this->assertSame(['orders.events.dlq'], $this->dlq->topics());
        $this->assertSame('always failing', $this->dlq->delivered[0]->headers['dlq_reason']);
        $this->assertSame('7', $this->dlq->delivered[0]->headers['original_offset']);
        $this->assertSame(0, DB::table('inbox_events')->count(), 'a dead-lettered event stays replayable');
        $this->assertSame(1, Metrics::value('nestlaravel_kafka_dlq_total', ['topic' => 'orders.events']));
    }

    public function test_non_retryable_business_error_skips_retries(): void
    {
        $calls = 0;
        $ok = $this->pipeline()->process($this->message(), function () use (&$calls) {
            $calls++;
            throw new OrderAlreadyCancelled('cancelled');
        });

        $this->assertFalse($ok);
        $this->assertSame(1, $calls);
        $this->assertCount(1, $this->dlq->delivered);
    }

    public function test_malformed_json_and_invalid_envelope_are_poison_and_never_reach_the_handler(): void
    {
        $called = false;
        $handler = function () use (&$called) { $called = true; };

        $this->assertFalse($this->pipeline()->process(new KafkaMessage('orders.events', 'k', '{oops'), $handler));
        $this->assertFalse($this->pipeline()->process($this->message('x', ['event_version' => 0]), $handler));
        $this->assertFalse($this->pipeline()->process($this->message('y', ['event_id' => '']), $handler));
        $this->assertFalse($this->pipeline()->process($this->message('z', ['occurred_at' => 'not-a-date']), $handler));

        $this->assertFalse($called);
        $this->assertCount(4, $this->dlq->delivered);
    }

    public function test_unsupported_newer_schema_version_is_dead_lettered(): void
    {
        config(['kafka.schema.max_version' => 1]);
        $this->app->forgetInstance(\NestLaravel\Kafka\KafkaConfig::class);
        $this->app->forgetInstance(ConsumerPipeline::class);

        $this->assertFalse($this->pipeline()->process($this->message('v2', ['event_version' => 2, 'version' => 2]), fn () => null));
        $this->assertCount(1, $this->dlq->delivered);
    }

    public function test_dlq_publish_failure_is_not_swallowed_so_the_offset_is_not_committed(): void
    {
        $this->dlq->brokerDown = true;

        $this->expectException(RuntimeException::class);
        $this->pipeline()->process($this->message(), fn () => throw new RuntimeException('boom'));
    }

    public function test_ambient_log_context_is_set_during_handling_and_cleared_after(): void
    {
        $seen = null;
        $this->pipeline()->process($this->message('evt-ctx'), function () use (&$seen) {
            $seen = LogContext::all();
        });

        $this->assertSame('evt-ctx', $seen['event_id']);
        $this->assertSame('corr-123', $seen['correlation_id']);
        $this->assertArrayHasKey('trace_id', $seen);
        $this->assertArrayNotHasKey('event_id', LogContext::all(), 'context must not leak to the next message');
    }

    public function test_events_created_while_handling_inherit_causation_and_correlation(): void
    {
        $child = null;
        $this->pipeline()->process($this->message('evt-parent'), function () use (&$child) {
            $child = new class extends \NestLaravel\Kafka\Events\AbstractDomainEvent
            {
                public function eventType(): string { return 'payments.payment.completed'; }
                public function aggregateId(): string { return 'o-1'; }
                public function aggregateType(): string { return 'payment'; }
                public function payload(): array { return []; }
            };
        });

        $envelope = $child->toArray();
        $this->assertSame('evt-parent', $envelope['causation_id']);
        $this->assertSame('corr-123', $envelope['correlation_id']);
        $this->assertSame(1, $envelope['event_version']);
    }
}
