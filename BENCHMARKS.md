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
  the 3 runs. The machine drifts between sessions by up to ~20–30 % (this document was measured four times while it was being
  written; where that matters the spread across sessions is stated). Treat differences of a few tens of microseconds as noise.
* The tests run inside a `RefreshDatabase` transaction, so every `DB::transaction` is a **SAVEPOINT** on SQLite. That changes the
  absolute cost of the inbox/duplicate paths compared with a real top-level transaction.
* "Before" is NestLaravel 1.0 behaviour (cache idempotency, no schema, no observability); "after" is 1.1 with the feature on.

## Results (latest 3 runs)

### Consumer: idempotency (one DB insert per message in the handler)

| | µs / message | messages / s |
|---|---:|---:|
| 1.0 default: cache-based check (`KAFKA_INBOX_ENABLED=false`) | 166 [164–170] | ~6 000 |
| 1.1 transactional inbox (`KAFKA_INBOX_ENABLED=true`) | 157 [156–184] | ~6 400 |
| **Difference (inbox − cache)** | **≈ −8 µs** [−9…+14] | |

**Conclusion: the inbox is not measurably slower than the array-cache check on this setup.** Across the four sessions this was
measured in, the difference ranged from **−8 to +48 µs per message** (an earlier session reported ≈ +23 µs). The honest reading is
"tens of microseconds at most, below what this harness resolves". The inbox does one unique-index `INSERT` inside the handler's
transaction; the cache check does a `get` and a `put` on a cache that is free here (`array`). With a Redis cache (network round
trips) and a PostgreSQL inbox (network + fsync) both sides will be much slower and the difference is **not measured**. What the
inbox buys is correctness: the effect is exactly-once (see [RELIABILITY.md](RELIABILITY.md)); the cache check is not atomic with
the database.

Skipping an **already-processed** event costs about 337 µs without log I/O (770 µs with the default file log channel; the
`Log::info` call itself is ≈ 22 µs). A duplicate being slower than fresh processing is counter-intuitive and was **not
explained** (suspect: rollback/savepoint handling of the ignored insert on SQLite inside the test transaction); it does not
affect correctness and duplicates are rare in production. Do not read it as the production cost of a duplicate.

### Producer: schema validation on publish

| | µs / event | events / s |
|---|---:|---:|
| Event without a schema | 392 [384–408] | ~2 550 |
| Event with a schema (3 rules) validated on publish | 524 [443–542] | ~1 900 |
| **Validation overhead** | **≈ +140 µs** [+35…+150] | |
| `EventSchema::validate()` alone | 108 [102–112] | ~9 300 |

Across sessions the overhead measured between +140 and +177 µs. Validation runs Laravel's validator per event. Every event class
that implements `HasEventSchema` (all generated ones) is validated, independent of `KAFKA_SCHEMA_ENFORCE_PRODUCER`, which only
decides whether events **without** a schema are refused. The cost grows with the number of rules and is paid inside the
request/transaction that publishes.

### Outbox publisher (fake producer: claim + bookkeeping only, no network)

| Batch size | rows / second |
|---|---:|
| 100 | ~2 190 [2 070–2 630] |
| 500 | ~2 190 [2 090–2 740] |

Earlier sessions measured ~1 650. Batch size does not matter here because the per-row cost is dominated by the row bookkeeping in
SQLite. Against a real broker the `flush()` round trip is amortised over the batch, so larger batches help there; that effect is
**not measured**.

### Observability primitives (per call, `array` cache)

| Operation | µs |
|---|---:|
| `Metrics::inc` (enabled) | 33.5 |
| `Metrics::inc` (`METRICS_ENABLED=false`) | 2.9 |
| `Redactor::redact` on a typical nested context | 4.7 |
| JSON log line formatting (incl. redaction) | 5.7 |
| `traceparent` continue + child span id | 1.8 |

HTTP middleware (`ObserveRequest`: request id, correlation id, trace context, RED metrics) versus the bare handler:

| | µs / request |
|---|---:|
| Handler only | 23.1 |
| Handler + middleware | 150.4 |
| **Middleware overhead** | **≈ +127 µs** [+100…+132] |

Most of the middleware cost is the metric writes: a counter is 1 cache write, a histogram observation 3. **This depends entirely
on the metrics cache store.** With `array` (measured here) a write is memory; with Laravel's default `database` store every
write is a SQL query; with Redis it is a network round trip. Both of those are **larger and were not benchmarked**; use
Redis in production (`nestlaravel production:check` warns for `database`/`file`/`array`) and switch the middleware off with
`OBSERVABILITY_HTTP=false` or `METRICS_ENABLED=false` if a measurement in your environment says it matters.
`nestlaravel_db_query_duration_seconds` (one observation per SQL query) is opt-in (`METRICS_DB_QUERIES=true`) for that reason.

## A related defect, found while preparing this release

The clean-install end-to-end test (`php artisan migrate` in a freshly created workspace died with a PHP fatal) exposed two real
bugs in the metrics code, both invisible to the unit tests because those use the `array` cache. Laravel's default cache store is
`database`, and that is what a generated workspace uses:

1. The query-duration listener wrote metrics to the database cache, which ran queries, which the listener measured again:
   unbounded recursion. Fixed with a re-entrancy guard in `Metrics::write()`.
2. Laravel's database cache store does **not** create a key on `increment()` (Redis and array do), so counters silently recorded
   nothing. Fixed by creating the key on first use.

Regression tests: `MetricsOnDatabaseCacheTest` (fails with an exhausted-memory fatal if the guard is removed).

## What was not measured

Real PostgreSQL/MySQL, Redis- or database-backed metrics, real Kafka produce/consume latency and throughput, the HMAC
verification path, the gateway circuit breaker/retry path, and behaviour under concurrency (the multi-process correctness test
runs in CI, but it asserts exactly-once behaviour, not throughput). There is no before/after for those.
