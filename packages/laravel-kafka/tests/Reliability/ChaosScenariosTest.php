<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Consumers\MessageHandler;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\KafkaConsumer;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Outbox\OutboxPublisher;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use NestLaravel\Kafka\Tests\Support\FakeProducer;
use RuntimeException;

/** Captures what it was asked to do so scenarios can assert business effects. */
final class ChargePaymentHandler implements MessageHandler
{
    public static ?\Closure $beforeCommit = null;

    public function handle(array $event): void
    {
        DB::table('payments')->insert(['order_id' => $event['payload']['order_id'], 'amount' => 100]);

        if (self::$beforeCommit) {
            (self::$beforeCommit)();
        }
    }
}

/** Broker whose log survives across "process restarts": a topic is a list, each consumer group has a committed offset. */
final class InMemoryBroker
{
    /** @var list<KafkaMessage> */
    public array $log = [];

    public int $committed = 0;

    public bool $down = false;

    public function producer(): KafkaProducer
    {
        $broker = $this;

        return new class($broker) implements KafkaProducer
        {
            private array $pending = [];

            public function __construct(private InMemoryBroker $broker) {}

            public function produce(KafkaMessage $message): void
            {
                if ($this->broker->down) {
                    throw new RuntimeException('broker unavailable');
                }
                $this->pending[] = $message;
            }

            public function produceMany(array $messages): void { array_map($this->produce(...), $messages); }

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

    public function consumer(): KafkaConsumer
    {
        $broker = $this;

        return new class($broker) implements KafkaConsumer
        {
            private int $position;

            public function __construct(private InMemoryBroker $broker)
            {
                $this->position = $broker->committed;   // a (re)started consumer resumes from the last COMMITTED offset
            }

            private array $topics = [];

            public function subscribe(array $topics): void { $this->topics = $topics; }

            public function consume(int $timeoutMs = 1000): ?KafkaMessage
            {
                // Like a real consumer: only messages of subscribed topics are delivered (other topics — e.g. the DLQ — are skipped).
                while (($m = $this->broker->log[$this->position] ?? null) !== null) {
                    $this->position++;
                    if ($this->topics === [] || in_array($m->topic, $this->topics, true)) {
                        return new KafkaMessage($m->topic, $m->key, $m->value, $m->headers, 0, $this->position - 1);
                    }
                }

                return null;
            }

            public function acknowledge(KafkaMessage $message): void { $this->broker->committed = $message->offset + 1; }

            public function close(): void {}
        };
    }
}

/**
 * Failure scenarios that must RECOVER correctly (not merely fail). Each one names the real-world event it models.
 */
class ChaosScenariosTest extends ReliabilityTestCase
{
    private InMemoryBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broker = new InMemoryBroker;
        ChargePaymentHandler::$beforeCommit = null;
        config([
            'kafka.inbox.enabled' => true, 'kafka.group_id' => 'payments-service',
            'kafka.consumer_options.error_backoff_ms' => 1, 'kafka.consumer_options.close_connections_on_exit' => false,
            'kafka.outbox.max_attempts' => 10,
        ]);
        $this->app->instance(KafkaProducer::class, $this->broker->producer());
        $this->app->forgetInstance(ConsumerPipeline::class);
        $this->app->forgetInstance(OutboxPublisher::class);
    }

    private function orderCreatedEvent(string $orderId = 'o-1'): void
    {
        $this->app->make(EventBus::class)->publish(new PaymentCompleted($orderId));
    }

    private function drain(): int
    {
        // A fresh consumer process every time == a restart.
        $this->app->instance(KafkaConsumer::class, $this->broker->consumer());
        $this->app->forgetInstance(ConsumerPipeline::class);

        return Artisan::call('kafka:consume', ['topic' => 'domain.events', 'handler' => ChargePaymentHandler::class, '--max' => 50]);
    }

    public function test_payment_service_crashes_after_db_commit_but_before_offset_commit_no_duplicate_payment(): void
    {
        $this->orderCreatedEvent('o-1');
        $this->app->make(OutboxPublisher::class)->publishPending();
        $this->assertCount(1, $this->broker->log);

        // Crash window: the DB transaction (payment + inbox) commits, then the process dies before the offset commit.
        $consumer = $this->broker->consumer();
        $message = $consumer->consume();
        $this->app->forgetInstance(ConsumerPipeline::class);
        $this->assertTrue($this->app->make(ConsumerPipeline::class)->process($message, new ChargePaymentHandler));
        // (no acknowledge(): the process was killed here)
        $this->assertSame(0, $this->broker->committed);
        $this->assertSame(1, DB::table('payments')->count());

        // Restart: Kafka redelivers from the last committed offset.
        $this->assertSame(0, $this->drain());

        $this->assertSame(1, DB::table('payments')->count(), 'event was delivered again, inbox detected the duplicate, no second payment');
        $this->assertSame(1, $this->broker->committed, 'and the offset is now committed');
    }

    public function test_kafka_broker_outage_and_restart_no_event_is_lost_and_none_duplicated(): void
    {
        $this->broker->down = true;
        $this->orderCreatedEvent('o-1');
        $this->orderCreatedEvent('o-2');

        $publisher = $this->app->make(OutboxPublisher::class);
        $this->assertSame(0, $publisher->publishPending(), 'broker down: nothing published');
        $this->assertSame(2, OutboxMessage::count(), 'events are safe in the database');

        $this->broker->down = false;
        $this->travel(2)->hours();                       // past the retry backoff
        $this->assertSame(2, $publisher->publishPending());

        $this->assertSame(0, $this->drain());
        $this->assertSame(2, DB::table('payments')->count());
    }

    public function test_outbox_publisher_crash_after_flush_before_status_update_is_absorbed_by_the_inbox(): void
    {
        $this->orderCreatedEvent('o-1');

        // Publisher claims + delivers, then dies before marking the row published.
        $publisher = $this->app->make(OutboxPublisher::class);
        $claimed = $publisher->claim(10);
        $producer = $this->broker->producer();
        foreach ($claimed as $row) {
            $producer->produce(new KafkaMessage($row->topic, $row->aggregate_id, json_encode($row->payload), payload: $row->payload));
        }
        $producer->flush();
        $this->assertSame(1, OutboxMessage::where('status', 'processing')->count());

        // Visibility timeout passes; a new publisher republishes the same event_id.
        $this->travel(10)->minutes();
        $this->assertSame(1, $this->app->make(OutboxPublisher::class)->publishPending());
        $this->assertCount(2, $this->broker->log, 'the event is on the topic twice (at-least-once)');

        $this->assertSame(0, $this->drain());
        $this->assertSame(1, DB::table('payments')->count(), 'consumers still charge once');
    }

    public function test_database_outage_during_handling_retries_then_recovers_on_redelivery(): void
    {
        $this->orderCreatedEvent('o-1');
        $this->app->make(OutboxPublisher::class)->publishPending();

        $fail = true;
        ChargePaymentHandler::$beforeCommit = function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('SQLSTATE[08006] connection failure');
            }
        };

        $this->assertSame(0, $this->drain());          // retries exhausted → parked on the DLQ, partition keeps moving
        $this->assertSame(0, DB::table('payments')->count(), 'failed attempts rolled back');
        $this->assertNotEmpty(array_filter($this->broker->log, fn ($m) => str_ends_with($m->topic, '.dlq')));

        // Operator replays the dead letter after the database is healthy again.
        $fail = false;
        $dead = collect($this->broker->log)->first(fn ($m) => str_ends_with($m->topic, '.dlq'));
        $original = $dead->payload;
        unset($original['consumer_error'], $original['original_topic']);
        $this->assertTrue($this->app->make(ConsumerPipeline::class)->process(new KafkaMessage('domain.events', $dead->key, json_encode($original)), new ChargePaymentHandler));
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_consumer_termination_mid_batch_resumes_without_skipping_or_repeating(): void
    {
        foreach (['o-1', 'o-2', 'o-3'] as $o) {
            $this->orderCreatedEvent($o);
        }
        $this->app->make(OutboxPublisher::class)->publishPending();

        // Consumer 1 processes two messages and is killed (the third never handled).
        $consumer = $this->broker->consumer();
        for ($i = 0; $i < 2; $i++) {
            $m = $consumer->consume();
            $this->app->make(ConsumerPipeline::class)->process($m, new ChargePaymentHandler);
            $consumer->acknowledge($m);
        }
        $this->assertSame(2, $this->broker->committed);

        $this->assertSame(0, $this->drain());           // restart

        $this->assertEqualsCanonicalizing(['o-1', 'o-2', 'o-3'], DB::table('payments')->pluck('order_id')->all());
        $this->assertSame(3, DB::table('payments')->count());
    }

    public function test_malformed_event_on_the_topic_does_not_block_the_partition(): void
    {
        $this->broker->log[] = new KafkaMessage('domain.events', 'k', '{this is not json');
        $this->orderCreatedEvent('o-1');
        $this->app->make(OutboxPublisher::class)->publishPending();

        $this->assertSame(0, $this->drain());

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertNotEmpty(array_filter($this->broker->log, fn ($m) => str_ends_with($m->topic, '.dlq')));
    }
}
