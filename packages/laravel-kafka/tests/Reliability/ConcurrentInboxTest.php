<?php

namespace NestLaravel\Kafka\Tests\Reliability;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

/**
 * REAL concurrency: several OS processes hit the same database with the same event_id at the same instant.
 * Needs a server database (SQLite serialises writers, so it proves nothing here). Runs in CI against PostgreSQL
 * and MySQL; locally set:
 *
 *   TEST_DB_DRIVER=pgsql TEST_DB_HOST=127.0.0.1 TEST_DB_DATABASE=nl_test TEST_DB_USERNAME=… TEST_DB_PASSWORD=… \
 *     vendor/bin/phpunit --filter ConcurrentInboxTest
 */
class ConcurrentInboxTest extends TestCase
{
    private Capsule $db;

    protected function setUp(): void
    {
        if (! getenv('TEST_DB_DATABASE')) {
            $this->markTestSkipped('Set TEST_DB_DRIVER/HOST/DATABASE/USERNAME/PASSWORD to run the multi-process inbox test.');
        }

        $this->db = new Capsule;
        $this->db->addConnection([
            'driver' => getenv('TEST_DB_DRIVER') ?: 'pgsql', 'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => getenv('TEST_DB_PORT') ?: null,
            'database' => getenv('TEST_DB_DATABASE'), 'username' => getenv('TEST_DB_USERNAME'), 'password' => getenv('TEST_DB_PASSWORD'),
            'charset' => 'utf8', 'prefix' => '',
        ]);
        $this->db->setAsGlobal();

        $schema = $this->db->schema();
        $schema->dropIfExists('payments');
        $schema->dropIfExists('inbox_events');
        $schema->create('payments', function ($t) {
            $t->id();
            $t->string('order_id');
            $t->unsignedInteger('amount');
        });
        $schema->create('inbox_events', function ($t) {
            $t->id();
            $t->string('consumer', 120);
            $t->string('event_id', 191);
            $t->string('event_type', 191)->nullable();
            $t->string('correlation_id', 191)->nullable();
            $t->string('tenant_id', 64)->nullable();
            $t->timestamp('processed_at')->useCurrent();
            $t->unique(['consumer', 'event_id']);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->schema()->dropIfExists('payments');
            $this->db->schema()->dropIfExists('inbox_events');
        }
    }

    /**
     * @param  list<list<string>>  $jobs  argv tails for each worker
     * @return list<string> stdout of each worker
     */
    private function runWorkers(array $jobs): array
    {
        $script = __DIR__.'/../Support/inbox_worker.php';
        $procs = [];

        foreach ($jobs as $i => $args) {
            $cmd = array_merge([PHP_BINARY, $script], $args);
            $pipes = [];
            $procs[$i] = [proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null), $pipes];
        }

        $out = [];
        foreach ($procs as $i => [$proc, $pipes]) {
            $out[$i] = trim((string) stream_get_contents($pipes[1])).trim((string) stream_get_contents($pipes[2]));
            proc_close($proc);
        }

        return $out;
    }

    public function test_many_processes_with_the_same_event_produce_exactly_one_business_effect(): void
    {
        $results = $this->runWorkers(array_fill(0, 6, ['evt-race', '400']));

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r === 'processed')), json_encode($results));
        $this->assertSame(5, count(array_filter($results, fn ($r) => $r === 'duplicate')), json_encode($results));
        $this->assertSame(1, $this->db->table('payments')->count(), 'six concurrent deliveries, one payment');
        $this->assertSame(1, $this->db->table('inbox_events')->count());
    }

    public function test_when_the_first_worker_fails_a_concurrent_duplicate_takes_over(): void
    {
        // worker 0 holds the row lock then fails (rollback); worker 1 was waiting on the unique index and must now proceed.
        $results = $this->runWorkers([['evt-takeover', '600', 'fail'], ['evt-takeover', '0']]);

        $this->assertContains('processed', $results, json_encode($results));
        $this->assertSame(1, $this->db->table('payments')->count(), 'the failed attempt left nothing behind; the takeover ran once');
    }

    public function test_distinct_events_are_processed_in_parallel(): void
    {
        $results = $this->runWorkers([['evt-a', '100'], ['evt-b', '100'], ['evt-c', '100']]);

        $this->assertSame(['processed', 'processed', 'processed'], $results);
        $this->assertSame(3, $this->db->table('payments')->count());
    }
}
