# Phase 2 Infrastructure Wiring

This document lists the exact env vars and provider registration needed for Redis, Kafka, Outbox, and EventBus.

## 1. Register the provider

Add `App\Providers\InfrastructureServiceProvider` to `bootstrap/providers.php`:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\InfrastructureServiceProvider;

return [
    AppServiceProvider::class,
    InfrastructureServiceProvider::class,
];
```

Do **not** skip this step — contracts will not resolve until the provider is registered.

## 2. Environment variables

Append (or set) the following in `.env`:

```env
# --- Redis (prefer predis; package is installed) ---
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=90
REDIS_QUEUE_BLOCK_FOR=5

REDIS_IDEMPOTENCY_PREFIX=idempotency:
REDIS_LOCK_PREFIX=lock:
INFRA_CACHE_STORE=redis

# --- Kafka ---
KAFKA_ENABLED=false
KAFKA_USE_OUTBOX=true
KAFKA_BROKERS=localhost:9092
KAFKA_CLIENT_ID=laravel-enterprise
KAFKA_GROUP_ID=laravel-enterprise-group
KAFKA_DLQ_SUFFIX=.dlq
KAFKA_PRODUCER=auto
KAFKA_CONSUMER=auto

KAFKA_TOPIC_DEFAULT=domain.events
KAFKA_TOPIC_VEHICLE=vehicle.events
KAFKA_TOPIC_ORDER=order.events
KAFKA_TOPIC_PAYMENT=payment.events
KAFKA_TOPIC_NOTIFICATION=notification.events
KAFKA_TOPIC_FRAUD=fraud.events

KAFKA_OUTBOX_BATCH_SIZE=100
KAFKA_OUTBOX_MAX_ATTEMPTS=5
KAFKA_OUTBOX_RETRY_DELAY=30
KAFKA_CONSUMER_MAX_RETRIES=3
KAFKA_IDEMPOTENCY_TTL=86400
# Optional override; default is storage/framework/kafka
# KAFKA_LOG_CONSUMER_PATH=
```

### Driver notes

| `KAFKA_ENABLED` | `KAFKA_PRODUCER` | Behavior |
|-----------------|------------------|----------|
| `false` | `auto` | `NullKafkaProducer` (debug log only) |
| `true` | `auto` + `ext-rdkafka` | `RdKafkaKafkaProducer` |
| `true` | `auto` without extension | `LogKafkaProducer` (JSONL under `storage/framework/kafka`) |
| any | `log` / `null` / `rdkafka` | Forced driver |

Consumers follow the same idea: without `ext-rdkafka`, `LogKafkaConsumer` reads the JSONL files written by `LogKafkaProducer` for local tests.

## 3. Migrations

```bash
php artisan migrate
```

Creates:

- `outbox_messages` — transactional outbox
- `processed_events` — optional DB idempotency fallback table

## 4. Contract bindings (provided by InfrastructureServiceProvider)

| Contract | Implementation |
|----------|----------------|
| `App\Core\Contracts\CacheStore` | `LaravelCacheStore` |
| `App\Core\Contracts\IdempotencyStore` | `RedisIdempotencyStore` |
| `App\Core\Contracts\DistributedLock` | `RedisDistributedLock` |
| `App\Core\Contracts\EventBus` | `OutboxEventBus` when `KAFKA_USE_OUTBOX=true`, else `LaravelEventBus` |
| `App\Infrastructure\Kafka\KafkaProducer` | Null / Log / RdKafka (see above) |
| `App\Infrastructure\Kafka\KafkaConsumer` | Null / Log |

## 5. Artisan commands

```bash
# Drain outbox → Kafka (or log/null producer)
php artisan messaging:outbox-publish
php artisan messaging:outbox-publish --limit=50

# Consume a topic with a MessageHandler implementation
php artisan kafka:consume domain.events "App\\Modules\\Example\\Application\\Handlers\\SomethingHandler"
php artisan kafka:consume domain.events "App\\...\\Handler" --max=10 --timeout=500
```

Schedule outbox publishing (in your scheduler, e.g. every minute):

```php
$schedule->command('messaging:outbox-publish')->everyMinute();
```

## 6. Usage sketch

```php
use App\Core\Contracts\EventBus;
use App\Core\Contracts\CacheStore;
use App\Core\Contracts\DistributedLock;
use App\Core\Contracts\IdempotencyStore;

// Inside a DB transaction for critical writes:
DB::transaction(function () use ($eventBus, $order) {
    // persist aggregate...
    $eventBus->publish(new OrderCreated($order->id));
});

// Worker (separate process):
// php artisan messaging:outbox-publish
```

## 7. EventBus modes

- **`KAFKA_USE_OUTBOX=true` (default):** `OutboxEventBus` inserts into `outbox_messages` (joins an open DB transaction when present) and dispatches Laravel events in-process.
- **`KAFKA_USE_OUTBOX=false`:** `LaravelEventBus` dispatches Laravel events and publishes immediately via `KafkaProducer`.
