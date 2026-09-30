# Reliability

What NestLaravel guarantees between services, how, and — just as important — what it does **not** guarantee.
Every guarantee below names the automated test that demonstrates it (`packages/laravel-kafka/tests/Reliability/*`,
`apps/*-service/tests`, `apps/api/tests`). If there is no test, it is listed under [Remaining risks](#remaining-risks),
not claimed.

## Delivery semantics (the honest summary)

* Kafka gives **at-least-once** delivery to consumers. Duplicates *will* happen (crash after commit, rebalance,
  producer retry after a lost ack).
* NestLaravel turns that into **effectively-once business effects** for anything a handler does inside its own
  database, using a transactional **outbox** on the way out and a transactional **inbox** on the way in.
* Effects **outside** your database (calling a payment provider, sending an email) are *not* covered by any
  transaction. Make those calls idempotent (idempotency keys) or drive them from an outbox event.
* Ordering is guaranteed per Kafka partition (events are keyed by `aggregate_id`). A retry of an outbox row can
  reorder events of one aggregate; handlers must tolerate that (compare versions/timestamps in your data).

```text
 Business transaction (service A)                     Consumer (service B)
 ┌──────────────────────────────┐                     ┌──────────────────────────────────────────┐
 │ UPDATE orders …              │                     │ receive → validate envelope + schema     │
 │ INSERT outbox_messages(event)│  atomic (same tx)   │ BEGIN                                    │
 └───────────────┬──────────────┘                     │   INSERT inbox(consumer,event_id) -- unique
                 │ COMMIT                             │   0 rows? → duplicate → COMMIT → ACK     │
   outbox publisher (claim → produce → flush)         │   handler()   -- your writes             │
                 │ broker-confirmed                   │ COMMIT                                   │
                 ▼                                    │ commit Kafka offset  (only now)          │
              Kafka  ─────────────────────────────▶   └──────────────────────────────────────────┘
```

## Idempotent consumption — the inbox

```php
// inside a MessageHandler (the consumer pipeline does this for you when KAFKA_INBOX_ENABLED=true)
app(EventInbox::class)->process($event['event_id'], fn () => $this->reserveStock($event));
```

| Failure | Result | Test |
|---------|--------|------|
| Same event delivered twice | second delivery is skipped, **one** business effect | `InboxTest::test_duplicate_delivery_produces_one_business_effect` |
| Handler throws | business writes **and** dedup record roll back; retry runs the handler once | `…test_handler_exception_rolls_back_business_and_dedup_record_so_retry_succeeds` |
| DB error inside handler | everything rolled back | `…test_db_failure_inside_handler_rolls_everything_back` |
| Second worker gets the event while the first is mid-transaction | second waits on the unique index, then skips (re-entrant variant tested; multi-process variant runs on PostgreSQL in CI) | `…test_concurrent_duplicate_inside_a_running_handler_is_skipped`, `ConcurrentInboxTest` |
| Crash after DB commit, before Kafka offset commit | event redelivered, deduplicated, **no duplicate payment** | `…test_consumer_crash_after_commit_before_offset_commit_is_harmless`, `PipelineReliabilityTest::test_redelivery_after_crash_between_db_commit_and_offset_commit_charges_once` |
| Retry after a transient failure | succeeds exactly once | `…test_transient_handler_failure_is_retried_and_succeeds_once` |

The inbox table (`inbox_events`, unique `(consumer, event_id)`) must live in the **same database** as the business
tables. Retention (`KAFKA_INBOX_RETENTION_DAYS`, default 14; `php artisan inbox:prune`) must exceed the longest
possible redelivery window (topic retention, consumer downtime).

## Transactional outbox

Events are written to `outbox_messages` **inside the caller's transaction** (`OutboxEventBus`) — the business change and
the event commit or roll back together (`OutboxReliabilityTest::test_business_row_and_outbox_row_commit_atomically`).

```text
pending ──claim──▶ processing ──flush ok──▶ published
   ▲                   │
   │ backoff           ├─ failure, attempts < max ─▶ pending (available_at = now + base·2^(n-1), capped)
   └───────────────────┤
                       ├─ failure, attempts ≥ max ─▶ failed  (+ copy on <topic>.dlq, last_error kept)
                       └─ publisher died (locked_at older than visibility timeout) ─▶ pending
```

* **No two publishers publish the same row**: claiming is a conditional `UPDATE … WHERE status='pending'` (portable,
  no `SKIP LOCKED` needed). Tests: `test_two_publishers_never_claim_the_same_row`,
  `test_racing_publishers_cannot_both_win_a_row_that_both_selected`.
* Rows are marked `published` **only after the broker confirmed delivery** (`flush()`), and only by the publisher that
  still owns the claim (`test_a_publisher_that_lost_its_claim_cannot_overwrite_the_new_owner`).
* Broker down → rows stay, retry with exponential backoff (`test_broker_down_keeps_rows_and_retries_with_exponential_backoff`).
* Publisher crash → rows recovered after `KAFKA_OUTBOX_VISIBILITY_TIMEOUT` (`test_publisher_crash_is_recovered…`).
* Batch size `KAFKA_OUTBOX_BATCH_SIZE`; graceful shutdown of the daemon on SIGTERM; `php artisan outbox:status`.
* Recovery can republish a row whose first publish actually succeeded (crash between flush and the status update):
  that duplicate is absorbed by consumers' inbox.

## Consumer failure handling

`kafka:consume` (test: `ConsumerFailureTest`, `PipelineReliabilityTest`)

| Situation | Behaviour |
|-----------|-----------|
| Handler throws | retried `KAFKA_CONSUMER_MAX_RETRIES` times with exponential backoff, then **DLQ** (`<topic>.dlq`) |
| Handler throws `NonRetryable` | straight to the DLQ |
| Malformed JSON / bad envelope / schema violation / unsupported version (*poison*) | straight to the DLQ, handler never runs |
| Offset commit | only **after** processing or dead-lettering; never before |
| DLQ publish fails | consumer exits **without committing** — the message is neither lost nor skipped |
| Offset commit fails | logged + counted, message will be redelivered and deduplicated |
| Broker unavailable / network flap | transient: exponential backoff, keeps running (`test_transient_broker_errors_back_off…`) |
| Authentication / TLS / ACL error | fatal: exits 1, does not hammer the broker |
| Topic missing | classified transient; set `KAFKA_CONSUMER_MAX_CONSECUTIVE_ERRORS` to escalate |
| SIGTERM | finishes the in-flight message, commits, leaves the group, closes DB/Redis, exits 0 |
| Rebalance | handled by librdkafka; unacknowledged messages are redelivered (inbox absorbs duplicates) |

Knobs: retry count/backoff, `--timeout`, `--max`, `--max-runtime`, `--memory`. **Concurrency** = number of consumer
processes in the same group (≤ partitions). **Batch size** at the consumer is 1 by design (offset safety).

## Event contract & schemas

```json
{ "event_id": "uuid", "event_type": "orders.order.created", "event_version": 1, "occurred_at": "…",
  "producer": "orders-service", "correlation_id": "uuid", "causation_id": "uuid|null", "tenant_id": "…|absent",
  "traceparent": "00-…-…-01|absent", "payload": { } }
```

* `version` is kept as a legacy alias of `event_version` (1.0 consumers keep working).
* Events created while handling another event inherit its `correlation_id` and use its `event_id` as `causation_id`
  (`test_events_created_while_handling_inherit_causation_and_correlation`).
* Every generated event declares a payload schema (Laravel validation rules); publishing an invalid event throws and
  persists nothing; consuming one dead-letters it (`SchemaGovernanceTest`).
* Compatibility gate: `php artisan events:check` fails when a new version *requires* a field the previous version was
  not guaranteed to carry. Rules: adding an optional field = compatible; making a field required, removing a required
  field, or changing meaning = new **major** `event_version`; deploy consumers first, then raise
  `KAFKA_EVENT_MAX_VERSION`, then the producer.
* Generate: `npx nestlaravel generate event order.created --service orders --version 1`.

## Sagas (long-running workflows)

See [SAGA.md](SAGA.md). Persisted state, idempotent start/resume, reverse-order compensation, timeouts, crash
recovery (`SagaTest`, 12 tests).

## Timeouts, retries and circuit breaking (gateway → service)

`ResilientHttp` (`ResilienceTest`, `GatewayResilienceTest`)

* Explicit connect + total timeout on every call; nothing waits forever.
* Retries only **transient** errors (connection failure, 408/425/429/5xx) of **safe** requests (GET/HEAD/OPTIONS, or a
  request with an `Idempotency-Key`, or `GATEWAY_RETRY_UNSAFE=true`). Auth (401/403), validation (400/422) and business
  (404/409/…) responses are never retried and never trip the breaker.
* Every retry is re-signed (fresh timestamp + single-use nonce).
* Circuit breaker `CLOSED → OPEN → HALF-OPEN`, state in the **shared cache** so all gateway replicas agree; 503 +
  `Retry-After` while open; if the cache is down the breaker lets traffic through.
* Client sees 502/503/504 without internal hostnames or curl errors.

## Failure modes of shared infrastructure

| Dependency down | What happens | Test |
|-----------------|--------------|------|
| **Kafka broker** | HTTP keeps working; events accumulate in the outbox (durable), published when the broker returns; readiness stays green unless you list `kafka` in `HEALTH_REQUIRED`; consumers back off | `test_broker_down_keeps_rows…`, `test_readiness_fails_only_for_required_dependencies` |
| **Redis** | optional caches degrade to the source of truth (`SafeCache`); circuit breaker fails open; **replay protection fails closed** (503) unless you opt out; rate limits/queues/sessions on Redis are unavailable — configure Laravel's `failover` cache/queue driver or accept the outage | `DegradedModeTest`, `test_requests_are_refused_when_replay_protection_cannot_be_guaranteed` |
| **Database** | readiness → 503 (traffic drained), liveness stays 200 (no restart storm); consumers retry then DLQ; outbox rows stay durable | `test_readiness_reports_a_down_database` |
| **Gateway replica** | stateless; breaker/rate-limit/nonce state in shared cache; any replica can serve any request | `test_breaker_state_is_shared_through_the_cache_not_process_memory` |
| **Payment service crashes after DB commit, before offset commit** | redelivery → inbox → no duplicate payment | `PipelineReliabilityTest`, `ChaosScenariosTest` |

## Database

* Statement timeout per session: `DB_STATEMENT_TIMEOUT_MS` (generated services: 15000) — pgsql `statement_timeout`,
  MySQL `max_execution_time`.
* Transactions: `Transactions::idempotent()` (DB-only body, deadlocks retried) vs `Transactions::once()` (may have
  external effects, never re-run). Laravel retries deadlocks only for the outermost transaction.
* Migrations: expand → deploy → contract; never rename/drop a column in the same release that stops using it.

## Remaining risks

Not claimed, because not proven by automated tests in this repository:

1. **Real multi-process inbox concurrency** is tested against PostgreSQL only in CI (`ConcurrentInboxTest`), not on
   MySQL/MariaDB; SQLite (tests) serialises writers.
2. **End-to-end with a real broker** covers produce → consume → DLQ (`RdKafkaBrokerTest`) in CI; broker restarts,
   network partitions and rebalance storms are **not** automated (documented drills in [OPERATIONS.md](OPERATIONS.md)).
3. **Graceful shutdown** is tested for the consumer and outbox daemon on Linux (pcntl). HTTP drain depends on your
   proxy/orchestrator settings (provided in `infrastructure/k8s`, not exercised here).
4. **OpenTelemetry** export is an OTLP/HTTP JSON exporter verified against a fake collector, not against a real
   Collector/Jaeger/Tempo.
5. **Kafka ACLs, TLS certificate rotation, broker replication** are operator responsibilities and are not verifiable
   from inside the application (`production:check` says so).
6. **Backups / restore** procedures are documented but the framework cannot test your restore ([DISASTER-RECOVERY.md](DISASTER-RECOVERY.md)).
7. **Outbox publisher** should run as a single instance per service for strict per-aggregate ordering; multiple
   publishers are safe (no double publish) but may reorder events of one aggregate.
8. **Performance overhead** is measured on a developer machine with SQLite ([BENCHMARKS.md](BENCHMARKS.md)); production
   numbers depend on your database and broker.
