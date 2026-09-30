<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Consumers\MessageHandler;
use NestLaravel\Kafka\KafkaConsumer;
use NestLaravel\Kafka\KafkaMessage;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\Support\KafkaErrorClassifier;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use NestLaravel\Kafka\Tests\Support\FakeProducer;
use RuntimeException;
use Throwable;

/** Scripted consumer: each poll returns the next item (message, null = idle, or an exception to throw). */
final class ScriptedConsumer implements KafkaConsumer
{
    /** @var list<KafkaMessage|Throwable|null> */
    public array $script = [];

    /** @var list<KafkaMessage> */
    public array $acked = [];

    public bool $closed = false;

    public bool $commitFails = false;

    public int $polls = 0;

    public function subscribe(array $topics): void {}

    public function consume(int $timeoutMs = 1000): ?KafkaMessage
    {
        $this->polls++;
        $next = array_shift($this->script);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function acknowledge(KafkaMessage $message): void
    {
        if ($this->commitFails) {
            throw new RuntimeException('commit failed: rebalance in progress');
        }

        $this->acked[] = $message;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class RecordingHandler implements MessageHandler
{
    /** @var list<string> */
    public static array $seen = [];

    public function handle(array $event): void
    {
        self::$seen[] = $event['event_id'];
        DB::table('payments')->insert(['order_id' => $event['payload']['order_id'], 'amount' => 1]);
    }
}

class ConsumerFailureTest extends ReliabilityTestCase
{
    private ScriptedConsumer $consumer;

    private FakeProducer $dlq;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kafka.inbox.enabled' => true,
            'kafka.group_id' => 'payments-service',
            'kafka.consumer_options.error_backoff_ms' => 1,
            'kafka.consumer_options.close_connections_on_exit' => false,
        ]);
        RecordingHandler::$seen = [];
        $this->consumer = new ScriptedConsumer;
        $this->dlq = new FakeProducer;
        $this->app->instance(KafkaConsumer::class, $this->consumer);
        $this->app->instance(KafkaProducer::class, $this->dlq);
        $this->app->forgetInstance(ConsumerPipeline::class);
    }

    private function msg(string $id, int $offset = 0): KafkaMessage
    {
        return new KafkaMessage('orders.events', 'o-'.$id, json_encode([
            'event_id' => $id, 'event_type' => 'orders.order.created', 'event_version' => 1,
            'aggregate_id' => 'o-'.$id, 'aggregate_type' => 'order', 'payload' => ['order_id' => 'o-'.$id],
        ]), partition: 0, offset: $offset);
    }

    private function consume(array $extra = []): int
    {
        return Artisan::call('kafka:consume', ['topic' => 'orders.events', 'handler' => RecordingHandler::class] + $extra);
    }

    public function test_offset_is_committed_only_after_successful_processing(): void
    {
        $this->consumer->script = [$this->msg('a', 1), $this->msg('b', 2), null];

        $this->assertSame(0, $this->consume(['--max' => 10]));

        $this->assertSame(['a', 'b'], RecordingHandler::$seen);
        $this->assertCount(2, $this->consumer->acked);
        $this->assertTrue($this->consumer->closed, 'group left cleanly');
    }

    public function test_transient_broker_errors_back_off_and_the_consumer_recovers(): void
    {
        $this->consumer->script = [
            new RuntimeException('Local: Broker transport failure'),
            new RuntimeException('Broker: Leader not available'),
            $this->msg('a'),
            null,
        ];

        $this->assertSame(0, $this->consume(['--max' => 10]));
        $this->assertSame(['a'], RecordingHandler::$seen, 'message processed after the outage');
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_authentication_and_tls_failures_are_fatal_and_stop_the_consumer(): void
    {
        foreach (['SASL authentication failed: invalid credentials', 'SSL handshake failed: certificate verify failed'] as $error) {
            $this->consumer->script = [new RuntimeException($error), $this->msg('never')];
            $this->consumer->polls = 0;

            $this->assertSame(1, $this->consume(['--max' => 10]), $error);
            $this->assertSame(1, $this->consumer->polls, 'must not busy-loop against the broker');
            $this->assertSame([], RecordingHandler::$seen);
        }
    }

    public function test_classifier_separates_fatal_from_transient(): void
    {
        $this->assertSame('fatal', KafkaErrorClassifier::classify('Topic authorization failed'));
        $this->assertSame('fatal', KafkaErrorClassifier::classify(new RuntimeException('ssl handshake')));
        $this->assertSame('transient', KafkaErrorClassifier::classify('Broker: Unknown topic or partition'));
        $this->assertSame('transient', KafkaErrorClassifier::classify('Local: Timed out'));
        $this->assertSame([500, 1000, 2000, 30000], [
            KafkaErrorClassifier::backoffMs(1), KafkaErrorClassifier::backoffMs(2), KafkaErrorClassifier::backoffMs(3), KafkaErrorClassifier::backoffMs(20),
        ]);
    }

    public function test_repeated_transient_errors_can_be_escalated_to_a_failure(): void
    {
        config(['kafka.consumer_options.max_consecutive_errors' => 3]);
        $this->consumer->script = array_fill(0, 5, new RuntimeException('Local: All broker connections are down'));

        $this->assertSame(1, $this->consume(['--max' => 10]));
        $this->assertSame(3, $this->consumer->polls);
    }

    public function test_offset_commit_failure_does_not_crash_and_redelivery_is_deduplicated(): void
    {
        $this->consumer->commitFails = true;
        $this->consumer->script = [$this->msg('a', 1), null];
        $this->assertSame(0, $this->consume(['--max' => 10]));
        $this->assertSame(1, DB::table('payments')->count());

        // Broker redelivers 'a' after the rebalance; the commit works this time.
        $this->consumer->commitFails = false;
        $this->consumer->script = [$this->msg('a', 1), null];
        $this->assertSame(0, $this->consume(['--max' => 10]));

        $this->assertSame(1, DB::table('payments')->count(), 'redelivered event did not repeat the business effect');
        $this->assertCount(1, $this->consumer->acked);
    }

    public function test_unavailable_dlq_stops_the_consumer_without_committing_the_poison_message(): void
    {
        $this->dlq->brokerDown = true;
        $this->consumer->script = [new KafkaMessage('orders.events', 'k', '{malformed', partition: 0, offset: 5), $this->msg('after', 6)];

        $this->assertSame(1, $this->consume(['--max' => 10]));

        $this->assertSame([], $this->consumer->acked, 'nothing committed: the message will be re-read after restart');
        $this->assertSame([], RecordingHandler::$seen, 'and later messages are not processed past it');
    }

    public function test_poison_message_is_parked_and_committed_so_the_partition_keeps_moving(): void
    {
        $this->consumer->script = [new KafkaMessage('orders.events', 'k', '{malformed', partition: 0, offset: 5), $this->msg('ok', 6), null];

        $this->assertSame(0, $this->consume(['--max' => 10]));

        $this->assertSame(['orders.events.dlq'], $this->dlq->topics());
        $this->assertSame(['ok'], RecordingHandler::$seen);
        $this->assertCount(2, $this->consumer->acked);
    }

    public function test_max_runtime_and_memory_limits_exit_cleanly(): void
    {
        $this->consumer->script = array_fill(0, 1000, null);

        $this->assertSame(0, $this->consume(['--max-runtime' => 1, '--timeout' => 1]));
        $this->assertTrue($this->consumer->closed);
    }
}
