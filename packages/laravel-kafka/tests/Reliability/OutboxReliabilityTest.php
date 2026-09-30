<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Contracts\EventBus;
use NestLaravel\Kafka\Events\AbstractDomainEvent;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaTopic;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Outbox\OutboxPublisher;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use NestLaravel\Kafka\Tests\Support\FakeProducer;
use RuntimeException;

final class PaymentCompleted extends AbstractDomainEvent
{
    public function __construct(private readonly string $orderId)
    {
        parent::__construct();
    }

    public function eventType(): string { return 'payments.payment.completed'; }
    public function aggregateId(): string { return $this->orderId; }
    public function aggregateType(): string { return 'payment'; }
    public function payload(): array { return ['order_id' => $this->orderId]; }
}

class OutboxReliabilityTest extends ReliabilityTestCase
{
    private function publisher(FakeProducer $producer, string $id, array $config = []): OutboxPublisher
    {
        $cfg = new KafkaConfig(['outbox' => $config + ['batch_size' => 50, 'max_attempts' => 3, 'retry_delay_seconds' => 30, 'max_retry_delay_seconds' => 300, 'visibility_timeout_seconds' => 120]]);

        return new OutboxPublisher($producer, $cfg, new KafkaTopic($cfg), workerId: $id);
    }

    private function seedEvents(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->app->make(EventBus::class)->publish(new PaymentCompleted("o-$i"));
        }
    }

    public function test_business_row_and_outbox_row_commit_atomically(): void
    {
        DB::transaction(function () {
            DB::table('payments')->insert(['order_id' => 'o-1', 'amount' => 5]);
            $this->app->make(EventBus::class)->publish(new PaymentCompleted('o-1'));
        });
        $this->assertSame([1, 1], [DB::table('payments')->count(), OutboxMessage::count()]);

        try {
            DB::transaction(function () {
                DB::table('payments')->insert(['order_id' => 'o-2', 'amount' => 5]);
                $this->app->make(EventBus::class)->publish(new PaymentCompleted('o-2'));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([1, 1], [DB::table('payments')->count(), OutboxMessage::count()], 'rolled-back transaction leaves neither');
    }

    public function test_two_publishers_never_claim_the_same_row(): void
    {
        $this->seedEvents(5);
        $a = $this->publisher(new FakeProducer, 'worker-a');
        $b = $this->publisher(new FakeProducer, 'worker-b');

        $claimedA = $a->claim(3);
        $claimedB = $b->claim(10);

        $this->assertCount(3, $claimedA);
        $this->assertCount(2, $claimedB, 'B only gets what A did not claim');
        $this->assertEmpty($claimedA->pluck('id')->intersect($claimedB->pluck('id')));
        $this->assertSame(5, OutboxMessage::where('status', 'processing')->count());
        $this->assertSame([], $b->claim(10)->all());
    }

    public function test_racing_publishers_cannot_both_win_a_row_that_both_selected(): void
    {
        $this->seedEvents(3);
        $a = $this->publisher(new FakeProducer, 'worker-a');
        $b = $this->publisher(new FakeProducer, 'worker-b');

        // Both publishers SELECT the same 3 candidates; B claims everything between A's SELECT and A's UPDATE.
        $bClaimed = null;
        $a->betweenSelectAndClaim(function () use ($b, &$bClaimed) {
            $bClaimed = $b->claim(10);
        });

        $aClaimed = $a->claim(10);

        $this->assertCount(3, $bClaimed);
        $this->assertCount(0, $aClaimed, 'A lost every race: the conditional UPDATE matched 0 rows');
        $this->assertSame(3, OutboxMessage::where('locked_by', 'worker-b')->count());
    }

    public function test_successful_publish_marks_rows_only_after_flush(): void
    {
        $this->seedEvents(3);
        $producer = new FakeProducer;
        $p = $this->publisher($producer, 'w');

        $this->assertSame(3, $p->publishPending());

        $this->assertSame(3, OutboxMessage::where('status', 'published')->count());
        $this->assertCount(3, $producer->delivered);
        $this->assertNotNull(OutboxMessage::first()->published_at);
        $this->assertNull(OutboxMessage::first()->locked_by);
        $this->assertSame(['pending' => 0, 'processing' => 0, 'published' => 3, 'failed' => 0], array_intersect_key($p->snapshot(), array_flip(['pending', 'processing', 'published', 'failed'])));
    }

    public function test_broker_down_keeps_rows_and_retries_with_exponential_backoff(): void
    {
        $this->seedEvents(1);
        $producer = new FakeProducer;
        $producer->brokerDown = true;
        $p = $this->publisher($producer, 'w');

        $this->assertSame(0, $p->publishPending());
        $row = OutboxMessage::first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertStringContainsString('broker unavailable', $row->last_error);
        $this->assertEqualsWithDelta(30, now()->diffInSeconds($row->available_at, false), 2, 'first backoff = base');

        // Not due yet → not picked up.
        $this->assertSame(0, $p->publishPending());
        $this->assertSame(1, OutboxMessage::first()->attempts);

        // Time passes, broker back → published, nothing lost.
        $this->travel(31)->seconds();
        $producer->brokerDown = false;
        $this->assertSame(1, $p->publishPending());
        $this->assertSame('published', OutboxMessage::first()->status);
    }

    public function test_backoff_doubles_and_is_capped(): void
    {
        $p = $this->publisher(new FakeProducer, 'w');

        $this->assertSame([30, 60, 120, 240, 300, 300], array_map($p->backoffSeconds(...), [1, 2, 3, 4, 5, 9]));
    }

    public function test_flush_failure_reschedules_every_row_in_the_batch(): void
    {
        $this->seedEvents(3);
        $producer = new FakeProducer;
        $producer->failFlush = true;
        $p = $this->publisher($producer, 'w');

        $this->assertSame(0, $p->publishPending());
        $this->assertSame(3, OutboxMessage::where('status', 'pending')->where('attempts', 1)->count());
        $this->assertSame([], $producer->delivered);
    }

    public function test_exhausted_retries_end_in_failed_state_with_a_dead_letter_copy(): void
    {
        $this->seedEvents(1);
        $producer = new FakeProducer;
        $p = $this->publisher($producer, 'w', ['max_attempts' => 2]);

        $producer->failFlush = true;
        $p->publishPending();                       // attempt 1 → retry
        $this->travel(120)->seconds();
        $producer->failFlush = false;
        $producer->brokerDown = false;
        $producer->failFlush = true;
        $p->publishPending();                       // attempt 2 → terminal

        $row = OutboxMessage::first();
        $this->assertSame('failed', $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertNotNull($row->last_error);

        // The DLQ copy could not be flushed (failFlush still on) but the row itself is durable and inspectable.
        $producer->failFlush = false;
        $this->assertSame(0, $p->publishPending(), 'failed rows are never retried automatically');
    }

    public function test_publisher_crash_is_recovered_after_the_visibility_timeout_and_published_once(): void
    {
        $this->seedEvents(2);

        // Publisher A claims the batch and dies (no produce, no finish).
        $dead = $this->publisher(new FakeProducer, 'dead-worker');
        $this->assertCount(2, $dead->claim(10));

        $producer = new FakeProducer;
        $b = $this->publisher($producer, 'worker-b');

        $this->assertSame(0, $b->publishPending(), 'rows are still owned by the (possibly slow) first publisher');

        $this->travel(121)->seconds();
        $this->assertSame(2, $b->publishPending(), 'stale claims recovered and published');
        $this->assertCount(2, $producer->delivered);
        $this->assertSame(2, OutboxMessage::where('status', 'published')->count());
    }

    public function test_a_publisher_that_lost_its_claim_cannot_overwrite_the_new_owner(): void
    {
        $this->seedEvents(1);
        $slow = $this->publisher(new FakeProducer, 'slow');
        $row = $slow->claim(1)->first();

        $this->travel(121)->seconds();
        $fast = $this->publisher(new FakeProducer, 'fast');
        $fast->recoverStale();
        $taken = $fast->claim(1)->first();
        $this->assertSame($row->id, $taken->id);

        $finish = new \ReflectionMethod(OutboxPublisher::class, 'finishPublished');
        $this->assertFalse($finish->invoke($slow, $row), 'stale owner must not mark the row published');
        $this->assertSame('processing', OutboxMessage::first()->status);
        $this->assertSame('fast', OutboxMessage::first()->locked_by);
    }

    public function test_invalid_event_is_rejected_before_anything_is_persisted(): void
    {
        $registry = $this->app->make(\NestLaravel\Kafka\Schema\EventSchemaRegistry::class);
        $registry->register(new \NestLaravel\Kafka\Schema\EventSchema('payments.payment.completed', 1, ['order_id' => 'required|integer']));

        try {
            $this->app->make(EventBus::class)->publish(new PaymentCompleted('not-an-int'));
            $this->fail('schema violation expected');
        } catch (\NestLaravel\Kafka\Exceptions\InvalidEventException $e) {
            $this->assertArrayHasKey('order_id', $e->errors);
        }

        $this->assertSame(0, OutboxMessage::count());
    }
}
