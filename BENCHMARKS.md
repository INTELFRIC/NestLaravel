# Benchmarks: what the reliability features cost

Measured, not estimated. Harness: [`packages/laravel-kafka/tests/Benchmarks/OverheadBenchmarkTest.php`](packages/laravel-kafka/tests/Benchmarks/OverheadBenchmarkTest.php);
raw results of the runs below: [`packages/laravel-kafka/benchmarks/results/run-{1,2,3}.json`](packages/laravel-kafka/benchmarks/results).

```bash
cd packages/laravel-kafka
composer bench            # sets NL_BENCH=1 and runs only the benchmark class (never part of `phpunit`)
```

## Read this before quoting a number

* **These are relative costs on a developer laptop**, not capacity figures. Environment: PHP 8.4.12 on Windows, SQLite 3.49 in
  memory, `array` cache, a fake Kafka producer. There is **no network, no PostgreSQL/MySQL, no Redis, no broker**. Your
  production numbers will differ, and the costs that involve I/O (metrics on Redis, inbox insert on PostgreSQL, produce/flush to
  Kafka) will be larger than shown here. Re-run the benchmark against your own stack before capacity planning.
* Each figure is the **median of 3 complete runs** (each run itself the best of several rounds); the bracket is min–max across
  the 3 runs. Between separate sessions on the same machine the absolute values moved by up to ~2× (the inbox overhead measured
  between 22 and 48 µs on different days), so treat differences below ~2× as "same order of magnitude", not as precise.
* The tests run inside a `RefreshDatabase` transaction, so every `DB::transaction` is a **SAVEPOINT** on SQLite. That changes the
  absolute cost of the inbox/duplicate paths compared with a real top-level transaction.
* "Before" is NestLaravel 1.0 behaviour (cache idempotency, no schema, no observability); "after" is 1.1 with the feature on.

## Results

### Consumer: idempotency (one DB insert per message in the handler)

| | µs / message | messages / s |
|---|---:|---:|
| 1.0 default: cache-based check (`KAFKA_INBOX_ENABLED=false`) | 203 [199–204] | ~4 900 |
| 1.1 transactional inbox (`KAFKA_INBOX_ENABLED=true`) | 227 [216–232] | ~4 400 |
| **Inbox overhead** | **≈ +23 µs** [+17…+28] (~11 %) | |

The inbox adds one unique-index `INSERT` and a transaction to each message. It is what makes the effect exactly-once
(see [RELIABILITY.md](RELIABILITY.md)); the cache check it replaces is not atomic with the database.

Skipping an **already-processed** event costs 417 µs without log I/O (709 µs with the default file log channel; the
`Log::info` call itself is ≈ 24 µs). A duplicate being slower than fresh processing is counter-intuitive and was **not
explained** (suspect: rollback/savepoint handling of the ignored insert on SQLite inside the test transaction); it does not
affect correctness and duplicates are rare in production. Do not read it as the production cost of a duplicate.

### Producer: schema validation on publish

| | µs / event | events / s |
|---|---:|---:|
| Event without a schema | 441 [440–449] | ~2 300 |
| Event with a schema (3 rules) validated on publish | 617 [597–618] | ~1 600 |
| **Validation overhead** | **≈ +177 µs** [+149…+177] | |
| `EventSchema::validate()` alone | 100 [100–104] | ~10 000 |

Validation runs Laravel's validator per event. Every event class that implements `HasEventSchema` (all generated ones) is validated,
independent of `KAFKA_SCHEMA_ENFORCE_PRODUCER`, which only decides whether events **without** a schema are refused. The cost
grows with the number of rules. It is paid inside the request/transaction that publishes.

### Outbox publisher (fake producer: claim + bookkeeping only, no network)

| Batch size | rows / second |
|---|---:|
| 100 | ~1 650 [1 650–1 680] |
| 500 | ~1 730 [1 660–1 760] |

Batch size barely matters here because the per-row cost is dominated by the row bookkeeping in SQLite. Against a real broker the
`flush()` round trip is amortised over the batch, so larger batches help there; that effect is **not measured**.

### Observability primitives (per call)

| Operation | µs |
|---|---:|
| `Metrics::inc` (enabled, array cache) | 33.5 |
| `Metrics::inc` (`METRICS_ENABLED=false`) | 3.0 |
| `Redactor::redact` on a typical nested context | 4.5 |
| JSON log line formatting (incl. redaction) | 6.0 |
| `traceparent` continue + child span id | 1.9 |

HTTP middleware (`ObserveRequest`: request id, correlation id, trace context, RED metrics) versus the bare handler:

| | µs / request |
|---|---:|
| Handler only | 23.3 |
| Handler + middleware | 150.9 |
| **Middleware overhead** | **≈ +128 µs** [+128…+132] |

Most of the middleware cost is the metric writes. With `CACHE_STORE=redis` each metric write is a network round trip, so this
overhead will be **larger** in production; it is not measured here. Switch it off with `OBSERVABILITY_HTTP=false` or
`METRICS_ENABLED=false` if a measurement in your environment says it matters.

## What was not measured

Real PostgreSQL/MySQL, Redis-backed metrics, real Kafka produce/consume latency and throughput, the HMAC verification path,
the gateway circuit breaker/retry path, and behaviour under concurrency (the multi-process correctness test runs in CI, but it
asserts exactly-once behaviour, not throughput). There is no before/after for those.
