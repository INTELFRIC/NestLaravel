# Kafka

Package: `packages/laravel-kafka` (`nestlaravel/kafka`, namespace `NestLaravel\Kafka`), installed in every generated
service. The gateway carries an equivalent copy under `App\Infrastructure\Kafka` / `App\Messaging`.

## Publishing (transactional outbox)

```php
use NestLaravel\Kafka\Contracts\EventBus;

DB::transaction(function () use ($order, $events) {
    $order->save();
    $events->publish(new OrderCreated((string) $order->id, ['total' => $order->total]));
});
```

`OutboxEventBus` writes the event to `outbox_messages` inside the caller's transaction (no dual-write problem).
`php artisan messaging:outbox-publish --daemon` (its own container/process) ships rows to Kafka:

1. `produce()` each row, then **`flush()`** — the producer callback throws on any failed delivery;
2. only then are rows marked `published`; failures are rescheduled (`KAFKA_OUTBOX_RETRY_DELAY`) up to
   `KAFKA_OUTBOX_MAX_ATTEMPTS`, then dead-lettered (`<topic>.dlq`) and marked `failed`.

Producer defaults: `acks=all`, `enable.idempotence=true`, `compression=lz4`, delivery timeout 30 s.
Run **one** outbox publisher per service. Delivery is at-least-once; consumers deduplicate.

## Consuming

```bash
php artisan kafka:consume orders.events "App\Modules\Payments\Infrastructure\Messaging\OrderCreatedHandler"
```

Pipeline per message: deserialize → validate envelope → **schema version check** (`KAFKA_EVENT_MAX_VERSION`) →
**idempotency check** (`event_id` per topic) → handler → remember → **commit offset**.

| Situation | Behaviour |
|-----------|-----------|
| Handler throws (transient) | retried `KAFKA_CONSUMER_MAX_RETRIES` times with exponential backoff, then → `<topic>.dlq` |
| Malformed JSON / missing fields / newer schema version (*poison*) | straight to the DLQ, no retries |
| Duplicate delivery | skipped (idempotency store: the app cache — use Redis in production) |
| DLQ publish itself fails | exception; **offset is not committed** → message is redelivered, never lost |
| SIGTERM/SIGINT | current message finishes, offset committed, consumer leaves the group (fast rebalance) |

Offsets: `enable.auto.commit=false`; offsets are stored/committed only after success (**at-least-once**).
Consumer groups default to the service name (`KAFKA_GROUP_ID`); scale by running more consumer processes
(≤ partitions). Rebalancing is handled by librdkafka.

## Topics & events

```bash
npx nestlaravel generate kafka-topic order-events --service orders --create   # + order-events.dlq
npx nestlaravel generate kafka-event order.created --service orders --consumer
```

* Events route to `topics[<aggregate_type>]`, else to `KAFKA_TOPIC_DEFAULT` (`<service>.events`).
* Naming: `<domain>.<entity>.<action>` past tense (`orders.order.created`). One topic per domain, DLQ = `<topic>.dlq`.
* **Versioning:** `version()` starts at 1. Add fields compatibly (consumers ignore unknown fields). For a breaking
  change bump `version()`, deploy consumers that understand it *first*, then raise `KAFKA_EVENT_MAX_VERSION` on
  them, then deploy the producer. Older consumers dead-letter unknown versions instead of guessing.
* **Correlation:** `correlation_id` is taken from the HTTP `X-Correlation-ID` (assigned by the gateway) and carried
  through events; `causation_id` links an event to the message that caused it. Message headers duplicate
  `event_id`, `event_type`, `correlation_id`.

## Security & operations

| Setting | Dev | Production |
|---------|-----|-----------|
| `KAFKA_SECURITY_PROTOCOL` | `plaintext` | `sasl_ssl` (or `ssl` with client certs) |
| `KAFKA_SASL_*`, `KAFKA_SSL_*` | – | from your secret store, **one principal per service** |
| Broker ACLs | – | service may WRITE only its own topics, READ only topics it consumes (+ its group); DLQ likewise |
| `auto.create.topics.enable` | true (compose) | false; provision topics with partitions/retention explicitly |
| Replication | 1 | ≥ 3, `min.insync.replicas=2` |

Observability: watch **consumer lag** (`kafka-consumer-groups.sh --describe` or Kafka UI: `docker compose --profile tools up -d kafka-ui`),
DLQ topic depth, outbox `pending`/`failed` row counts, and `Log::critical` "Failed to publish … to DLQ".

## Local development

`docker-compose.infra.yml` runs a single-node **KRaft** broker (`apache/kafka:4.0.0`; no ZooKeeper) on
`127.0.0.1:9092` (host) / `kafka:29092` (containers). Without `php-rdkafka` on the host, apps use the log driver
(JSONL under `storage/framework/kafka`) so you can develop and test without a broker; `nestlaravel dev --docker`
runs the real thing.
