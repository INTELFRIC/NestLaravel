<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Support\Facades\DB;
use NestLaravel\Kafka\Inbox\EventInbox;
use NestLaravel\Kafka\Inbox\InboxResult;
use NestLaravel\Kafka\Observability\Metrics;
use NestLaravel\Kafka\Tests\ReliabilityTestCase;
use RuntimeException;

class InboxTest extends ReliabilityTestCase
{
    private function inbox(): EventInbox
    {
        return $this->app->make(EventInbox::class);
    }

    private function charge(string $order): void
    {
        DB::table('payments')->insert(['order_id' => $order, 'amount' => 100]);
    }

    public function test_duplicate_delivery_produces_one_business_effect(): void
    {
        $first = $this->inbox()->process('evt-1', fn () => $this->charge('o-1'), 'payments');
        $second = $this->inbox()->process('evt-1', fn () => $this->charge('o-1'), 'payments');

        $this->assertSame(InboxResult::Processed, $first);
        $this->assertSame(InboxResult::Duplicate, $second);
        $this->assertSame(1, DB::table('payments')->count(), 'process(event); process(event) must not charge twice');
    }

    public function test_handler_exception_rolls_back_business_and_dedup_record_so_retry_succeeds(): void
    {
        $attempt = 0;

        try {
            $this->inbox()->process('evt-2', function () use (&$attempt) {
                $attempt++;
                $this->charge('o-2');
                throw new RuntimeException('downstream timeout');
            }, 'payments');
            $this->fail('exception expected');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('payments')->count(), 'partial business write must be rolled back');
        $this->assertFalse($this->inbox()->hasProcessed('evt-2', 'payments'), 'no dedup record after a failed attempt');

        // Retry after the failure is fixed → runs exactly once.
        $result = $this->inbox()->process('evt-2', function () use (&$attempt) {
            $attempt++;
            $this->charge('o-2');
        }, 'payments');

        $this->assertSame(InboxResult::Processed, $result);
        $this->assertSame(2, $attempt);
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_db_failure_inside_handler_rolls_everything_back(): void
    {
        try {
            $this->inbox()->process('evt-3', function () {
                $this->charge('o-3');
                DB::table('payments')->insert(['order_id' => null, 'amount' => 1]); // NOT NULL violation
            }, 'payments');
            $this->fail('query exception expected');
        } catch (\Throwable) {
        }

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertFalse($this->inbox()->hasProcessed('evt-3', 'payments'));
    }

    public function test_concurrent_duplicate_inside_a_running_handler_is_skipped(): void
    {
        // Simulates a second worker receiving the same event while the first is still mid-transaction
        // (same connection here; the multi-process variant runs against PostgreSQL in CI).
        $innerRan = false;
        $innerResult = null;

        $this->inbox()->process('evt-4', function () use (&$innerRan, &$innerResult) {
            $this->charge('o-4');
            $innerResult = $this->inbox()->process('evt-4', function () use (&$innerRan) {
                $innerRan = true;
                $this->charge('o-4');
            }, 'payments');
        }, 'payments');

        $this->assertSame(InboxResult::Duplicate, $innerResult);
        $this->assertFalse($innerRan);
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_consumer_crash_after_commit_before_offset_commit_is_harmless(): void
    {
        // Worker 1: handler + dedup committed, then the process dies before acknowledging the offset.
        $this->inbox()->process('evt-5', fn () => $this->charge('o-5'), 'payments');

        // Kafka redelivers to worker 2 (or the restarted worker 1).
        $again = $this->inbox()->process('evt-5', fn () => $this->charge('o-5'), 'payments');

        $this->assertSame(InboxResult::Duplicate, $again);
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_dedup_is_scoped_per_consumer(): void
    {
        $count = 0;
        $this->inbox()->process('evt-6', function () use (&$count) { $count++; }, 'payments');
        $this->inbox()->process('evt-6', function () use (&$count) { $count++; }, 'notifications');

        $this->assertSame(2, $count, 'two different services each get to process the same event once');
    }

    public function test_retention_prunes_only_old_records(): void
    {
        $this->inbox()->process('old', fn () => null, 'c');
        DB::table('inbox_events')->where('event_id', 'old')->update(['processed_at' => now()->subDays(30)]);
        $this->inbox()->process('fresh', fn () => null, 'c');

        $this->assertSame(1, $this->inbox()->prune(14));
        $this->assertFalse($this->inbox()->hasProcessed('old', 'c'));
        $this->assertTrue($this->inbox()->hasProcessed('fresh', 'c'));
    }

    public function test_metrics_and_logs_record_results(): void
    {
        $this->inbox()->process('evt-7', fn () => null, 'payments');
        $this->inbox()->process('evt-7', fn () => null, 'payments');

        $this->assertSame(1, Metrics::value('nestlaravel_inbox_total', ['consumer' => 'payments', 'result' => 'processed']));
        $this->assertSame(1, Metrics::value('nestlaravel_inbox_total', ['consumer' => 'payments', 'result' => 'duplicate']));
        $this->assertStringContainsString('nestlaravel_inbox_total{consumer="payments",result="duplicate"} 1', Metrics::render());
    }
}
