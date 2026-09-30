<?php

namespace NestLaravel\Kafka\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Events\AbstractDomainEvent;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Outbox\OutboxPublisher;
use RuntimeException;

final class UserCreated extends AbstractDomainEvent
{
    public function __construct(private readonly string $id)
    {
        parent::__construct();
    }

    public function eventType(): string
    {
        return 'users.user.created';
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function aggregateType(): string
    {
        return 'user';
    }

    public function payload(): array
    {
        return ['id' => $this->id];
    }
}

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function test_published_event_uses_the_standard_envelope_and_lands_in_the_outbox(): void
    {
        config(['app.name' => 'users-service']);
        $event = new UserCreated('42');

        $this->app->make(EventBus::class)->publish($event);

        $row = OutboxMessage::query()->firstOrFail();
        $this->assertSame($event->eventId(), $row->event_id);
        $this->assertSame('pending', $row->status);

        foreach (['event_id', 'event_type', 'version', 'occurred_at', 'source', 'correlation_id', 'payload'] as $field) {
            $this->assertArrayHasKey($field, $row->payload);
        }

        $this->assertSame('users-service', $row->payload['source']);
        $this->assertSame(1, $row->payload['version']);
    }

    public function test_rows_are_marked_published_only_after_a_confirmed_flush(): void
    {
        $this->app->make(EventBus::class)->publish(new UserCreated('1'));

        $producer = new class implements KafkaProducer
        {
            public int $flushes = 0;

            public bool $failFlush = false;

            public function produce(KafkaMessage $message): void {}

            public function produceMany(array $messages): void {}

            public function flush(int $timeoutMs = 1000): void
            {
                $this->flushes++;

                if ($this->failFlush) {
                    throw new RuntimeException('delivery failed');
                }
            }
        };

        $publisher = new OutboxPublisher($producer, $this->app->make(\NestLaravel\Kafka\KafkaConfig::class), $this->app->make(\NestLaravel\Kafka\KafkaTopic::class));

        // Broker rejects the batch: the row must stay pending and be retried later.
        $producer->failFlush = true;
        $this->assertSame(0, $publisher->publishPending());
        $row = OutboxMessage::query()->firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertTrue($row->available_at->isFuture());

        // Broker recovers: once the retry delay has passed the row is published.
        $producer->failFlush = false;
        $row->forceFill(['available_at' => now()->subSecond()])->save();
        $this->assertSame(1, $publisher->publishPending());
        $this->assertSame('published', OutboxMessage::query()->firstOrFail()->status);
    }
}
