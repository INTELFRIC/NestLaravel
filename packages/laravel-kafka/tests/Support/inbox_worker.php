<?php

/**
 * Standalone worker process used by ConcurrentInboxTest: boots a minimal container around Illuminate's database
 * layer, connects to a REAL PostgreSQL/MySQL server and runs the production EventInbox class.
 *
 *   php inbox_worker.php <event_id> <sleep_ms> [fail]
 *
 * Prints "processed" or "duplicate" (or "failed:<message>") on stdout.
 */

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use NestLaravel\Kafka\Inbox\EventInbox;
use Psr\Log\NullLogger;

require __DIR__.'/../../vendor/autoload.php';

[, $eventId, $sleepMs] = $argv + [null, 'evt', '0'];
$fail = in_array('fail', $argv, true);

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => getenv('TEST_DB_DRIVER') ?: 'pgsql',
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('TEST_DB_PORT') ?: null,
    'database' => getenv('TEST_DB_DATABASE'),
    'username' => getenv('TEST_DB_USERNAME'),
    'password' => getenv('TEST_DB_PASSWORD'),
    'charset' => 'utf8',
    'prefix' => '',
]);

$capsule->setAsGlobal();   // Capsule::table() below needs the static instance

$app = new Container;
Container::setInstance($app);
$app->instance('db', $capsule->getDatabaseManager());
$app->instance('config', new Repository(['kafka' => ['metrics' => ['enabled' => false]]]));
$app->instance('log', new NullLogger);
Facade::setFacadeApplication($app);

try {
    $result = (new EventInbox('inbox_events', 'worker'))->process($eventId, function () use ($sleepMs, $fail) {
        Capsule::table('payments')->insert(['order_id' => 'o-1', 'amount' => 100]);
        usleep((int) $sleepMs * 1000);          // hold the transaction open so the other workers really collide
        if ($fail) {
            throw new RuntimeException('handler failed');
        }
    });
    echo $result->value;
} catch (Throwable $e) {
    echo 'failed:'.$e->getMessage();
}
