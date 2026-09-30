# Failure scenarios

For every dependency: what the caller sees, what the system does, how it recovers, and which automated test proves
the *logic* (tests use SQLite + an in-memory broker; see [RELIABILITY.md](RELIABILITY.md#remaining-risks) for what that does
and does not prove). "Drill" means: reproduce it in staging with the real component.

Legend: ✅ proven by an automated test · 🔧 provided by config/manifests, not exercised by tests · ⚠️ your responsibility

## 1. Kafka broker down

```
client ─▶ gateway ─▶ orders ──tx──▶ [orders + outbox row]   ✅ HTTP 2xx, event durable
                                        │ publisher: produce fails → backoff 30s·2ⁿ (cap 15m) → status stays retryable
                                        ▼
                                  broker returns → backlog drains → consumers' inbox absorbs any duplicates
```

* Caller: unaffected. `/readiness` stays 200 unless `kafka` is in `HEALTH_REQUIRED`. `/health` shows `degraded`.
* Alert: `outbox_oldest_pending_age_seconds`. After `KAFKA_OUTBOX_MAX_ATTEMPTS` rows become `failed` (copy on the DLQ topic
  once the broker is back) → `outbox:status --failed`, then `--requeue`.
* Tests ✅ `ChaosScenariosTest::test_kafka_broker_outage_and_restart_no_event_is_lost_and_none_duplicated`,
  `OutboxReliabilityTest::test_broker_down_keeps_rows_and_retries_with_exponential_backoff`.
* Consumers: transient client errors back off exponentially (500 ms → 30 s) and keep running; `KAFKA_CONSUMER_MAX_CONSECUTIVE_ERRORS`
  makes them exit so the orchestrator shows a crash loop (✅ `ConsumerFailureTest`). Auth/TLS errors exit immediately.

## 2. Consumer crashes

```
receive ─▶ BEGIN ─ inbox INSERT ─ handler writes ─ COMMIT ─▶ [CRASH] ─▶ offset not committed
restart ─▶ redelivery ─▶ inbox INSERT ignored (duplicate) ─▶ handler NOT run ─▶ commit offset
```

* Any crash point is safe: before COMMIT everything rolls back and is redone; after COMMIT the duplicate is skipped.
* ✅ `ChaosScenariosTest::test_payment_service_crashes_after_db_commit_but_before_offset_commit_no_duplicate_payment`,
  `test_consumer_termination_mid_batch_resumes_without_skipping_or_repeating`.
* ⚠️ Effects *outside* the database (charging a card, sending mail) are not covered; use idempotency keys with the provider.

## 3. Outbox publisher crashes

* After claim, before flush: rows are `processing`; after the visibility timeout (`KAFKA_OUTBOX_VISIBILITY_TIMEOUT`, 300 s)
  another publisher recovers them ✅ `test_publisher_crash_is_recovered…`.
* After flush, before marking published: the event is published a second time; consumers deduplicate ✅
  `ChaosScenariosTest::test_outbox_publisher_crash_after_flush_before_status_update_is_absorbed_by_the_inbox`.
* Two publishers at once never publish the same row ✅ `test_two_publishers_never_claim_the_same_row`,
  `test_racing_publishers_cannot_both_win_a_row_that_both_selected`.
* SIGTERM: current batch completes, then exit 0 ✅ (Linux) `GracefulShutdownTest`.

## 4. Database down or slow

```
request ─▶ DB error ─▶ 500/503 (no partial writes: event + data commit together)
/liveness 200 (no restart storm)   /readiness 503 (drained from LB)   consumer: retry ×3 → DLQ (parked, partition moves on)
```

* Statement timeout (`DB_STATEMENT_TIMEOUT_MS`) prevents one slow query holding a worker forever 🔧.
* Deadlocks: `Transactions::idempotent()` retries a DB-only body; `Transactions::once()` never re-runs bodies with
  external effects ✅ `TransactionsTest`.
* ✅ `ChaosScenariosTest::test_database_outage_during_handling_retries_then_recovers_on_redelivery`,
  `HealthTest::test_readiness_reports_a_down_database`.
* After recovery, replay dead letters (fix cause → re-publish original payload); the inbox makes it safe.

## 5. Redis down

| Function | Behaviour |
|----------|-----------|
| Application caches (`SafeCache`) | bypass to source of truth for a short window, error counter ✅ `DegradedModeTest` |
| Circuit breaker | fails **open** (traffic flows) ✅ |
| HMAC replay protection | fails **closed**: 503 to the caller, because accepting unverifiable requests would silently disable replay protection ✅ `GatewaySignatureTest`. Opt out with `INTERNAL_REPLAY_PROTECTION_REQUIRED=false` if availability matters more |
| Metrics | dropped; requests unaffected |
| Rate limit / sessions / Redis queues | unavailable: use Laravel's `failover` drivers or accept the outage ⚠️ |

## 6. Downstream service down (gateway → service)

```
gateway ─timeout 3s connect / total─▶ service
   transient (conn error, 408/425/429/5xx) & safe method (or Idempotency-Key) → retry with backoff, re-signed
   N failures → breaker OPEN (shared in cache, all replicas agree) → 503 + Retry-After without calling the service
   half-open probe → close on success
```

* Client sees 502/503/504 with a generic message – never internal hostnames ✅ `GatewayResilienceTest`.
* 401/403/400/422/404/409 are *answers*, not failures: never retried, never trip the breaker ✅ `ResilienceTest`.
* Unsafe methods (POST) are not retried unless an `Idempotency-Key` header is present ✅.

## 7. Poison message

Malformed JSON, missing envelope fields, schema violation, unsupported `event_version`, or `NonRetryable` → straight to
`<topic>.dlq`, handler never runs, offset committed, partition keeps moving ✅ `PipelineReliabilityTest`,
`SchemaGovernanceTest`, `ChaosScenariosTest::test_malformed_event_on_the_topic_does_not_block_the_partition`.
If even the DLQ publish fails the consumer exits **without committing** – the message is never silently dropped ✅.
Inspect with `nestlaravel dlq:list <topic>`.

## 8. Long-running workflow fails halfway

Saga compensation in reverse order, timeouts, retries and crash recovery ✅ `SagaTest` (see [SAGA.md](SAGA.md)). A compensation
that keeps failing parks the saga as `failed` and logs at `critical`.

## 9. Tenant context lost

Queued jobs carry `tenant_id`; events carry it in the envelope; logs always include it. With `TENANCY_STRICT_JOBS=true`
dispatching a job with no tenant is refused ✅ `TenantPropagationTest`. `nestlaravel tenant:check` audits models vs tables ✅
`TenantCheckTest`. ⚠️ Raw `DB::table()` queries bypass model scopes; review them.

## 10. Deploy / restart

Rolling updates with `maxUnavailable: 0`, `/startup` gating, `preStop` delay and `terminationGracePeriodSeconds` 🔧
(`infrastructure/k8s`, validated by kubeconform). Consumers and the outbox daemon stop cleanly on SIGTERM ✅ (Linux).

## What is not covered by any test in this repository

Network partitions between services and the broker, broker rebalance storms, disk-full on the database, clock skew beyond
the HMAC timestamp window, and multi-region failover. Rehearse them with the drills in [OPERATIONS.md](OPERATIONS.md#7-failure-drills-run-them-in-staging-the-framework-cannot-run-them-for-you).
