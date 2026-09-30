<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Events\AbstractDomainEvent;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use Tests\TestCase;

class KafkaKitTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_are_written_to_the_outbox_with_the_standard_envelope(): void
    {
        $event = new class extends AbstractDomainEvent
        {
            public function eventType(): string
            {
                return 'orders.order.created';
            }

            public function aggregateId(): string
            {
                return '1001';
            }

            public function aggregateType(): string
            {
                return 'order';
            }

            public function payload(): array
            {
                return ['total' => 12];
            }
        };

        $this->app->make(EventBus::class)->publish($event);

        $row = OutboxMessage::query()->firstOrFail();
        $this->assertSame('orders.order.created', $row->event_type);
        $this->assertSame(1, $row->payload['version']);
        $this->assertArrayHasKey('correlation_id', $row->payload);
        $this->assertArrayHasKey('source', $row->payload);
    }

    public function test_kafka_commands_are_registered(): void
    {
        $this->artisan('list')->expectsOutputToContain('kafka:consume')->assertSuccessful();
    }
}
