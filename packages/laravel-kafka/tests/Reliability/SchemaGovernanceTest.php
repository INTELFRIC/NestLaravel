<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Contracts\HasEventSchema;
use NestLaravel\Kafka\Events\AbstractDomainEvent;
use NestLaravel\Kafka\Exceptions\InvalidEventException;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Schema\EventSchema;
use NestLaravel\Kafka\Schema\EventSchemaRegistry;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use NestLaravel\Kafka\Tests\Support\FakeProducer;

final class OrderCreatedV1 extends AbstractDomainEvent implements HasEventSchema
{
    public function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public static function eventSchema(): EventSchema
    {
        return new EventSchema('orders.order.created', 1, ['order_id' => 'required|string', 'total' => 'required|numeric|min:0', 'coupon' => 'nullable|string']);
    }

    public function eventType(): string { return 'orders.order.created'; }
    public function aggregateId(): string { return (string) ($this->data['order_id'] ?? 'x'); }
    public function aggregateType(): string { return 'order'; }
    public function payload(): array { return $this->data; }
}

class SchemaGovernanceTest extends ReliabilityTestCase
{
    public function test_envelope_contains_the_governed_fields(): void
    {
        $e = (new OrderCreatedV1(['order_id' => 'o-1', 'total' => 10]))->toArray();

        foreach (['event_id', 'event_type', 'event_version', 'occurred_at', 'producer', 'correlation_id', 'causation_id', 'payload'] as $field) {
            $this->assertArrayHasKey($field, $e);
        }
        $this->assertSame(1, $e['event_version']);
        $this->assertSame($e['event_version'], $e['version'], 'legacy `version` alias kept for 1.0 consumers');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $e['event_id']);
    }

    public function test_valid_payload_passes_and_invalid_payload_reports_each_field(): void
    {
        $schema = OrderCreatedV1::eventSchema();
        $schema->validate(['order_id' => 'o-1', 'total' => 9.5]);

        try {
            $schema->validate(['total' => -1]);
            $this->fail('expected InvalidEventException');
        } catch (InvalidEventException $e) {
            $this->assertEqualsCanonicalizing(['order_id', 'total'], array_keys($e->errors));
        }
    }

    public function test_unknown_fields_are_allowed_unless_the_schema_is_strict(): void
    {
        $lenient = new EventSchema('t', 1, ['a' => 'required']);
        $lenient->validate(['a' => 1, 'extra' => 2]);

        $this->expectException(InvalidEventException::class);
        (new EventSchema('t', 1, ['a' => 'required'], strict: true))->validate(['a' => 1, 'extra' => 2]);
    }

    public function test_publishing_an_invalid_event_is_refused_and_nothing_is_persisted(): void
    {
        $this->app->make(EventSchemaRegistry::class)->registerClass(OrderCreatedV1::class);

        $this->expectException(InvalidEventException::class);
        try {
            $this->app->make(EventBus::class)->publish(new OrderCreatedV1(['order_id' => 'o-1', 'total' => 'abc']));
        } finally {
            $this->assertSame(0, OutboxMessage::count());
        }
    }

    public function test_producer_enforcement_rejects_events_without_a_schema(): void
    {
        config(['kafka.schema.enforce_producer' => true]);
        $this->app->forgetInstance(\NestLaravel\Kafka\KafkaConfig::class);
        $this->app->forgetInstance(EventSchemaRegistry::class);
        $this->app->forgetInstance(EventBus::class);

        $schemaless = new class extends AbstractDomainEvent
        {
            public function eventType(): string { return 'misc.thing.happened'; }
            public function aggregateId(): string { return '1'; }
            public function aggregateType(): string { return 'thing'; }
            public function payload(): array { return ['anything' => true]; }
        };

        $this->expectException(InvalidEventException::class);
        $this->app->make(EventBus::class)->publish($schemaless);
    }

    public function test_consumer_dead_letters_events_that_violate_their_registered_schema(): void
    {
        config(['kafka.events' => [OrderCreatedV1::class]]);
        $this->app->forgetInstance(EventSchemaRegistry::class);
        $dlq = new FakeProducer;
        $this->app->instance(KafkaProducer::class, $dlq);
        $this->app->forgetInstance(ConsumerPipeline::class);

        $bad = new KafkaMessage('orders.events', 'o-1', json_encode([
            'event_id' => 'e-1', 'event_type' => 'orders.order.created', 'event_version' => 1,
            'aggregate_id' => 'o-1', 'aggregate_type' => 'order', 'payload' => ['order_id' => 'o-1'],
        ]));
        $called = false;

        $this->assertFalse($this->app->make(ConsumerPipeline::class)->process($bad, function () use (&$called) { $called = true; }));
        $this->assertFalse($called, 'handler never sees an event that violates its schema');
        $this->assertStringContainsString('payload is invalid', $dlq->delivered[0]->headers['dlq_reason']);
    }

    public function test_events_of_unregistered_types_pass_through_so_shared_topics_work(): void
    {
        config(['kafka.events' => [OrderCreatedV1::class]]);
        $this->app->forgetInstance(EventSchemaRegistry::class);
        $this->app->instance(KafkaProducer::class, new FakeProducer);
        $this->app->forgetInstance(ConsumerPipeline::class);

        $other = new KafkaMessage('orders.events', 'k', json_encode([
            'event_id' => 'e-2', 'event_type' => 'orders.order.shipped', 'event_version' => 1,
            'aggregate_id' => 'o-1', 'aggregate_type' => 'order', 'payload' => ['whatever' => 1],
        ]));

        $this->assertTrue($this->app->make(ConsumerPipeline::class)->process($other, fn () => null));
    }

    public function test_backward_compatibility_check_flags_new_required_fields(): void
    {
        $registry = new EventSchemaRegistry;
        $registry->register(new EventSchema('orders.order.created', 1, ['order_id' => 'required|string']));
        $registry->register(new EventSchema('orders.order.created', 2, ['order_id' => 'required|string', 'currency' => 'nullable|string']));
        $registry->register(new EventSchema('orders.order.created', 3, ['order_id' => 'required|string', 'currency' => 'required|string']));

        $this->assertSame([], $registry->compatibilityProblems('orders.order.created', 1, 2), 'adding an optional field is compatible');
        $problems = $registry->compatibilityProblems('orders.order.created', 2, 3);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('currency', $problems[0]);
    }
}
