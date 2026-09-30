<?php

namespace NestLaravel\Kafka\Tests\Reference;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NestLaravel\Kafka\Consumers\ConsumerPipeline;
use NestLaravel\Kafka\Consumers\MessageHandler;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaProducer;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Outbox\OutboxPublisher;
use NestLaravel\Kafka\Saga\Saga;
use NestLaravel\Kafka\Saga\SagaInstance;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use NestLaravel\Kafka\Tests\Support\InMemoryBroker;

require_once __DIR__.'/CheckoutApp.php';

/**
 * End-to-end proof of the reference flow (see REFERENCE-APP.md). Three "services" are consumer groups on one in-memory
 * broker; each does only its own work and replies with events. The failure cases are the point of the exercise:
 * every one of them ends in a consistent state, and none needs a human.
 */
class CheckoutSagaTest extends ReliabilityTestCase
{
    private InMemoryBroker $broker;

    /** @var array<string, string> group => event type on which the consumer "crashes" after its DB commit, once */
    private array $crashOnce = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $t) {
            $t->string('order_id')->primary();
            $t->string('sku');
            $t->unsignedInteger('qty');
            $t->unsignedInteger('amount');
            $t->string('status');
        });
        Schema::create('stock', function (Blueprint $t) {
            $t->string('sku')->primary();
            $t->integer('qty');
        });
        Schema::create('reservations', function (Blueprint $t) {
            $t->string('order_id')->primary();
            $t->string('sku');
            $t->unsignedInteger('qty');
            $t->boolean('released')->default(false);
        });

        DB::table('stock')->insert(['sku' => 'widget', 'qty' => 10]);

        config(['kafka.inbox.enabled' => true, 'kafka.use_outbox' => true, 'kafka.consumer_options.close_connections_on_exit' => false, 'kafka.topics.default' => 'domain.events']);
        $this->broker = new InMemoryBroker;
        $this->crashOnce = [];
        $this->app->instance(KafkaProducer::class, $this->broker->producer());
        $this->app->forgetInstance(OutboxPublisher::class);

        Saga::flush();
        CheckoutSaga::define();
    }

    private function checkout(string $orderId, string $card = 'ok', int $qty = 2): SagaInstance
    {
        return Saga::start(CheckoutSaga::NAME, ['order_id' => $orderId, 'sku' => 'widget', 'qty' => $qty, 'amount' => 50, 'card' => $card], $orderId);
    }

    /** Let the "platform" run: publish outboxes and let every service consume until nothing moves any more. */
    private function settle(): void
    {
        $handlers = ['orders' => new OrdersHandler, 'inventory' => new InventoryHandler, 'payments' => new PaymentsHandler];

        for ($round = 0; $round < 60; $round++) {
            $work = 0;
            while (($n = $this->app->make(OutboxPublisher::class)->publishPending()) > 0) {
                $work += $n;
            }
            foreach ($handlers as $group => $handler) {
                $work += $this->drain($group, $handler);
            }
            if ($work === 0) {
                return;
            }
        }

        $this->fail('The flow did not settle (livelock?).');
    }

    private function drain(string $group, MessageHandler $handler): int
    {
        // Each service is its own consumer group ⇒ its own inbox namespace and its own offset.
        config(['kafka.group_id' => $group]);
        $this->app->forgetInstance(KafkaConfig::class);
        $this->app->forgetInstance(ConsumerPipeline::class);
        $pipeline = $this->app->make(ConsumerPipeline::class);

        $consumer = $this->broker->consumer($group);
        $consumer->subscribe(['domain.events']);
        $count = 0;

        while (($message = $consumer->consume()) !== null) {
            $handled = $pipeline->process($message, $handler);
            $count++;

            $type = json_decode($message->value, true)['event_type'];
            if (($this->crashOnce[$group] ?? null) === $type) {
                unset($this->crashOnce[$group]);

                return $count;      // process "dies" after the DB commit, BEFORE the offset commit
            }

            if ($handled) {
                $consumer->acknowledge($message);
            }
        }

        return $count;
    }

    private function orderStatus(string $orderId): string
    {
        return (string) DB::table('orders')->where('order_id', $orderId)->value('status');
    }

    private function stock(): int
    {
        return (int) DB::table('stock')->where('sku', 'widget')->value('qty');
    }

    public function test_happy_path_order_is_confirmed_stock_reserved_payment_taken(): void
    {
        $this->checkout('o-1');
        $this->settle();

        $this->assertSame('confirmed', $this->orderStatus('o-1'));
        $this->assertSame(8, $this->stock());
        $this->assertSame(1, DB::table('payments')->where('order_id', 'o-1')->count());
        $this->assertSame(SagaInstance::COMPLETED, SagaInstance::firstWhere('correlation_id', 'o-1')->status);

        $this->assertSame([
            'orders.order.created',
            'inventory.reserve.requested',
            'inventory.stock.reserved',
            'payments.charge.requested',
            'payments.payment.completed',
            'orders.order.confirmed',
        ], $this->broker->eventTypes());
    }

    public function test_payment_failure_releases_the_stock_and_cancels_the_order(): void
    {
        $this->checkout('o-2', card: 'declined');
        $this->settle();

        $this->assertSame('cancelled', $this->orderStatus('o-2'));
        $this->assertSame(10, $this->stock(), 'reserved stock was given back');
        $this->assertSame(0, DB::table('payments')->count(), 'nothing was charged');
        $this->assertSame(SagaInstance::COMPENSATED, SagaInstance::firstWhere('correlation_id', 'o-2')->status);

        $types = $this->broker->eventTypes();
        $this->assertContains('payments.payment.failed', $types);
        $this->assertNotContains('orders.order.confirmed', $types);
        $this->assertLessThan(
            array_search('orders.order.cancelled', $types, true),
            array_search('inventory.release.requested', $types, true),
            'compensation runs in reverse order: the order is cancelled last',
        );
    }

    public function test_out_of_stock_cancels_before_any_payment_is_requested(): void
    {
        $this->checkout('o-3', qty: 99);
        $this->settle();

        $this->assertSame('cancelled', $this->orderStatus('o-3'));
        $this->assertSame(10, $this->stock());
        $this->assertNotContains('payments.charge.requested', $this->broker->eventTypes(), 'no money was ever asked for');
    }

    public function test_duplicate_kafka_delivery_of_every_message_changes_nothing(): void
    {
        $this->broker->duplicateDeliveries = true;   // every message is delivered twice, to every consumer

        $this->checkout('o-4');
        $this->settle();

        $this->assertSame('confirmed', $this->orderStatus('o-4'));
        $this->assertSame(8, $this->stock(), 'stock decremented once, not twice');
        $this->assertSame(1, DB::table('reservations')->where('order_id', 'o-4')->count());
        $this->assertSame(1, DB::table('payments')->where('order_id', 'o-4')->count(), 'the customer was charged once');
        $this->assertSame(1, SagaInstance::count());

        foreach (['orders', 'inventory', 'payments'] as $group) {
            $this->assertGreaterThan(0, Metrics::value('nestlaravel_inbox_total', ['consumer' => $group, 'result' => 'duplicate']), "$group really saw duplicates");
        }
    }

    public function test_payments_crash_after_db_commit_before_offset_commit_charges_once(): void
    {
        $this->crashOnce['payments'] = 'payments.charge.requested';

        $this->checkout('o-5');
        $this->settle();

        $this->assertSame(1, DB::table('payments')->where('order_id', 'o-5')->count(), 'redelivered charge request was deduplicated');
        $this->assertSame('confirmed', $this->orderStatus('o-5'));
        $this->assertGreaterThan(0, Metrics::value('nestlaravel_inbox_total', ['consumer' => 'payments', 'result' => 'duplicate']));
    }

    public function test_starting_the_same_checkout_twice_creates_one_order_and_one_charge(): void
    {
        $this->checkout('o-6');
        $this->checkout('o-6');                      // client retry / double click
        $this->settle();

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(8, $this->stock());
    }

    public function test_a_lost_reply_is_recovered_by_the_saga_timeout(): void
    {
        $this->checkout('o-7');
        $this->broker->down = true;                  // broker outage: the reserve request never leaves the outbox
        $this->app->make(OutboxPublisher::class)->publishPending();

        $this->travel(90)->seconds();                // beyond the 60 s step timeout
        $this->assertSame(1, $this->app->make(\NestLaravel\Kafka\Saga\SagaOrchestrator::class)->recoverOverdue());

        $this->broker->down = false;
        $this->travel(30)->minutes();                // past outbox retry backoff
        $this->settle();

        $this->assertSame('cancelled', $this->orderStatus('o-7'));
        $this->assertSame(SagaInstance::COMPENSATED, SagaInstance::firstWhere('correlation_id', 'o-7')->status);
        $this->assertSame(10, $this->stock(), 'a late reservation request, if it arrives, is released again');
    }
}
