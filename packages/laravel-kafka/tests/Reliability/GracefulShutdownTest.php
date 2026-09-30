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

/** Sends SIGTERM to the running process while it is in the middle of handling message #1. */
final class TermDuringHandlingHandler implements MessageHandler
{
    public static int $handled = 0;

    public function handle(array $event): void
    {
        self::$handled++;
        DB::table('payments')->insert(['order_id' => $event['payload']['order_id'], 'amount' => 1]);

        if (self::$handled === 1) {
            posix_kill(getmypid(), SIGTERM);     // orchestrator says: stop
            usleep(50_000);                      // the handler is still running when the signal arrives
        }
    }
}

/**
 * SIGTERM handling of the long-running processes (needs ext-pcntl + ext-posix: Linux/macOS, i.e. CI and containers).
 */
class GracefulShutdownTest extends ReliabilityTestCase
{
    protected function setUp(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('ext-pcntl/ext-posix required (Linux/macOS).');
        }

        parent::setUp();
        TermDuringHandlingHandler::$handled = 0;
        config(['kafka.inbox.enabled' => true, 'kafka.consumer_options.close_connections_on_exit' => false]);
    }

    protected function tearDown(): void
    {
        // Restore default signal disposition so PHPUnit itself is not affected by the command's handlers.
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }
        parent::tearDown();
    }

    public function test_consumer_finishes_the_inflight_message_commits_it_and_stops_taking_new_ones(): void
    {
        $consumer = new ScriptedConsumer;
        $consumer->script = [$this->message('m1', 1), $this->message('m2', 2), $this->message('m3', 3)];
        $this->app->instance(KafkaConsumer::class, $consumer);
        $this->app->instance(KafkaProducer::class, new FakeProducer);
        $this->app->forgetInstance(ConsumerPipeline::class);

        $code = Artisan::call('kafka:consume', ['topic' => 'orders.events', 'handler' => TermDuringHandlingHandler::class]);

        $this->assertSame(0, $code, 'clean exit');
        $this->assertSame(1, TermDuringHandlingHandler::$handled, 'no NEW message is started after SIGTERM');
        $this->assertSame(1, DB::table('payments')->count(), 'the in-flight message was completed, not abandoned half-way');
        $this->assertCount(1, $consumer->acked, 'and its offset was committed');
        $this->assertTrue($consumer->closed, 'consumer left the group (fast rebalance)');
        $this->assertCount(2, $consumer->script, 'm2 and m3 stay on the broker for another consumer');
    }

    public function test_outbox_daemon_finishes_its_batch_then_exits_zero(): void
    {
        $this->app->make(EventBus::class)->publish(new PaymentCompleted('o-1'));

        $producer = new class extends FakeProducer
        {
            public function flush(int $timeoutMs = 1000): void
            {
                posix_kill(getmypid(), SIGTERM);   // SIGTERM lands while the batch is being flushed
                parent::flush($timeoutMs);
            }
        };
        $this->app->instance(KafkaProducer::class, $producer);
        $this->app->forgetInstance(OutboxPublisher::class);

        $code = Artisan::call('messaging:outbox-publish', ['--daemon' => true, '--sleep' => 1]);

        $this->assertSame(0, $code);
        $this->assertSame(1, OutboxMessage::where('status', 'published')->count(), 'the batch in progress was completed and recorded');
        $this->assertCount(1, $producer->delivered);
    }

    private function message(string $id, int $offset): KafkaMessage
    {
        return new KafkaMessage('orders.events', $id, json_encode([
            'event_id' => $id, 'event_type' => 'orders.order.created', 'event_version' => 1,
            'aggregate_id' => $id, 'aggregate_type' => 'order', 'payload' => ['order_id' => $id],
        ]), partition: 0, offset: $offset);
    }
}
