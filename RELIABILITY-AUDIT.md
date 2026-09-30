# Reliability audit (baseline: NestLaravel 1.0.0)

Every row was checked against the source, not the documentation. **Status** = state *before* the 1.1 hardening work;
the **After** column is filled in at the end of this document once the work is implemented *and* covered by tests.

Legend: COMPLETE · PARTIAL · MISSING · UNSAFE (works but can lose/duplicate data or fail open) · NEEDS TESTING

| # | Capability | Evidence in 1.0.0 | Status |
|---|-----------|-------------------|--------|
| 1 | CLI (create/generate/dev/test/lint/build/update/doctor) | `packages/cli` — tested from tarball | COMPLETE |
| 2 | Generated Laravel services | `service-template` + `make:microservice` | COMPLETE |
| 3 | Gateway auth + rate limits | Sanctum + `throttle:api`; limiter store = app cache (shared only if cache store is shared) | PARTIAL |
| 4 | Gateway → service HMAC | timestamp (60 s), single-use nonce, body hash; **one static secret per service, no rotation** | PARTIAL |
| 5 | Gateway timeouts / retries / breaker | one `timeout` per service; **no connect timeout, no retry policy, no circuit breaker** | MISSING |
| 6 | Event envelope | `event_id, event_type, version, occurred_at, producer/source, correlation_id, causation_id, payload (+tenant_id)`; no `event_version` name, **payload not schema-validated** | PARTIAL |
| 7 | Producer | `acks=all`, idempotence, flush-before-mark | COMPLETE (NEEDS TESTING vs real broker: CI job) |
| 8 | Transactional outbox | row written in caller's transaction ✔; **no claim/lock → two publishers publish the same row**; no `last_error`; no stale-`processing` recovery; fixed (non-exponential) retry delay | UNSAFE with >1 publisher |
| 9 | Inbox / idempotency | `IdempotencyStore::has()` → handler → `remember()` on the **cache**: not atomic with the business transaction; a crash between handler and `remember`, or a cache flush, ⇒ duplicate business effect; concurrent duplicates both run | UNSAFE |
| 10 | Consumer pipeline | retry + backoff + DLQ ✔, poison → DLQ ✔, schema-version guard ✔, offset commit after processing ✔, failed DLQ publish ⇒ no ack ✔; **no error classification, `acknowledge()` failure unhandled, transient broker errors crash the command** | PARTIAL |
| 11 | Graceful shutdown | consumer + outbox daemon handle SIGTERM/SIGINT (pcntl); nginx/php-fpm/supervisor use defaults; **no test** | NEEDS TESTING |
| 12 | Saga / workflows | none | MISSING |
| 13 | Structured logs | gateway has `StructuredLogger`; services: JSON formatter env only, `correlation_id` in context; no service/env/event/tenant/trace fields, **no redaction** | PARTIAL |
| 14 | Metrics | none (no `/metrics`, no counters) | MISSING |
| 15 | Tracing | correlation id only; no W3C trace context, no OpenTelemetry | MISSING |
| 16 | Health | gateway `/health,/health/live,/health/ready`; services `/up`, `/ready` (DB only); no startup probe; Kafka/Redis not covered | PARTIAL |
| 17 | Gateway horizontal scaling | stateless except rate-limit/nonce/breaker state (needs shared cache) — undocumented | NEEDS TESTING |
| 18 | Multi-tenancy | gateway signs tenant; scope, jobs, Kafka, cache/path helpers, tests ✔; **logs carry no tenant**, no strict-mode for tenant-less jobs | PARTIAL |
| 19 | Secret handling | no secrets in repo/tarball ✔; **no rotation without downtime** | PARTIAL |
| 20 | Database reliability | Laravel defaults; no statement timeout, no documented deadlock/retry policy, no migration-safety guidance | MISSING |
| 21 | Redis failure mode | cache/session/queue/idempotency assume Redis is up; behaviour undefined | UNSAFE (undefined) |
| 22 | Docker | multi-stage image, rdkafka, health check ✔; supervisor stop signal/timeouts default | PARTIAL |
| 23 | Kubernetes | **none in repo** (DEPLOYMENT.md prose only) | MISSING |
| 24 | CI | unit + Kafka broker integration + clean-install; **no failure/chaos/concurrency tests** | PARTIAL |
| 25 | Disaster recovery docs | none | MISSING |
| 26 | Production-readiness CLI | `doctor` checks tools only | PARTIAL |
| 27 | Reference application | none (only `orders/payments/notifications` skeletons) | MISSING |
| 28 | Performance measurements | none | MISSING |

Design decisions taken from this audit are recorded in [RELIABILITY.md](RELIABILITY.md); what remains unproven is listed in
its *Remaining risks* section.
