# Reliability audit: 1.0.0 baseline → 1.1.0

Every row was checked against the source, not the documentation. **Before** = NestLaravel 1.0.0. **After** = state at 1.1.0,
with the automated evidence. A row is only marked COMPLETE when a test proves the behaviour; anything not proven is listed
as PARTIAL / NEEDS TESTING with the reason. The full list of unproven items is in [RELIABILITY.md § Remaining risks](RELIABILITY.md#remaining-risks).

Legend: COMPLETE · PARTIAL · MISSING · UNSAFE (works but can lose/duplicate data or fail open) · NEEDS TESTING

| # | Capability | Before (1.0.0) | After (1.1.0) | Evidence |
|---|-----------|----------------|---------------|----------|
| 1 | CLI | COMPLETE | COMPLETE (+`generate event`, `production:check`, `events:list/check`, `kafka:health`, `outbox:status`, `dlq:list`, `tenant:check`, richer `doctor`) | `packages/cli/test/*.test.js` (19 tests) |
| 2 | Generated Laravel services | COMPLETE | COMPLETE (health probes, metrics, inbox, structured logs) | service feature tests |
| 3 | Gateway auth + rate limits | PARTIAL (limiter store per instance) | PARTIAL: state is in the shared cache; requires a shared `CACHE_STORE` for >1 replica (checked by `production:check`, documented) | `GatewayResilienceTest`, `ProductionChecker` |
| 4 | Gateway → service HMAC | PARTIAL (no rotation) | COMPLETE: zero-downtime rotation (`…_PREVIOUS`), replay protection fails closed if the nonce store is down | `GatewaySignatureTest` (per service) |
| 5 | Timeouts / retries / circuit breaker | MISSING | COMPLETE for gateway → service (connect+total timeouts, error classification, safe-only retries, shared breaker, 502/503/504) | `ResilienceTest`, `GatewayResilienceTest` |
| 6 | Event envelope + schemas | PARTIAL | COMPLETE: `event_version`, causation/correlation inheritance, schema registry, compatibility gate | `SchemaGovernanceTest`, `OpsCommandsTest` |
| 7 | Producer | COMPLETE (NEEDS TESTING vs broker) | COMPLETE: real-broker produce → consume → DLQ in CI | `RdKafkaBrokerTest` (CI job) |
| 8 | Transactional outbox | UNSAFE with >1 publisher | COMPLETE: atomic claim, backoff, stale recovery, lost-claim protection, DLQ copy, metrics. Ordering across several publishers is still not guaranteed (documented) | `OutboxReliabilityTest`, `ChaosScenariosTest` |
| 9 | Inbox / idempotency | UNSAFE | COMPLETE (opt-in `KAFKA_INBOX_ENABLED`, on in the generated Compose stack): dedup + business writes atomic; multi-process race verified on PostgreSQL and MySQL | `InboxTest`, `ConcurrentInboxTest` (CI, pgsql + mysql) |
| 10 | Consumer pipeline | PARTIAL | COMPLETE: classification, no ack before success, DLQ failure ⇒ no commit, commit failure tolerated, broker backoff | `ConsumerFailureTest`, `PipelineReliabilityTest` |
| 11 | Graceful shutdown | NEEDS TESTING | COMPLETE for consumer + outbox daemon on Linux (real SIGTERM in flight); HTTP drain is orchestrator config (manifests provided, not deployed by CI) | `GracefulShutdownTest` (CI) |
| 12 | Saga / workflows | MISSING | COMPLETE for the orchestrated model (persisted, idempotent, timeouts, compensation, crash recovery); a failing compensation parks the saga for a human | `SagaTest` (12), `CheckoutSagaTest` |
| 13 | Structured logs | PARTIAL | COMPLETE: JSON, correlation/trace/tenant ids, redaction (heuristic, documented) | `ObservabilityTest` |
| 14 | Metrics | MISSING | COMPLETE: Prometheus `/metrics`, token-protected, fails closed; cache-store dependent | `ObservabilityTest`, `OperationsTest` |
| 15 | Tracing | MISSING | PARTIAL: W3C propagation through HTTP → events → consumers ✔; OTLP export verified against a fake collector only | `ObservabilityTest` |
| 16 | Health | PARTIAL | COMPLETE: liveness independent of dependencies, startup, readiness by `HEALTH_REQUIRED`, degraded `/health` | `OpsEndpointsTest`, service `OperationsTest` |
| 17 | Gateway horizontal scaling | NEEDS TESTING | PARTIAL: breaker/nonce/limiter state proven to live in the shared cache (not process memory); multi-replica behaviour itself not load-tested | `ResilienceTest::test_breaker_state_is_shared_through_the_cache_not_process_memory` |
| 18 | Multi-tenancy | PARTIAL | COMPLETE for the tested paths (Eloquent, queues, events, logs, strict jobs, audit command); raw `DB::table()` bypasses scopes by design (documented) | `TenantPropagationTest`, `TenantCheckTest` |
| 19 | Secret handling | PARTIAL | COMPLETE for service-to-service secret rotation; other secrets are operator-managed (documented) | `GatewaySignatureTest` |
| 20 | Database reliability | MISSING | PARTIAL: statement timeout, `Transactions::idempotent/once`, migration guidance. No test against a real failover | `DegradedModeTest`, `DegradedModeTest` |
| 21 | Redis failure mode | UNSAFE (undefined) | COMPLETE for the framework's own uses (defined degrade/fail-open/fail-closed per function); Laravel's Redis queue/session/rate-limit are still Redis-dependent unless you configure `failover` drivers | `DegradedModeTest`, `GatewaySignatureTest` |
| 22 | Docker | PARTIAL | COMPLETE: `stop_grace_period`, structured logs, inbox on; image build in CI | CI "Docker images build + compose config" |
| 23 | Kubernetes | MISSING | PARTIAL: reference manifests, schema-validated in CI; not deployed to a cluster by CI | CI "Kubernetes manifests are valid" |
| 24 | CI | PARTIAL | COMPLETE for the listed suites: unit/feature (PHP 8.3, 8.4), real Kafka, pgsql + mysql concurrency, Linux signals, manifests, security audit, CLI on Node 20/22/24, clean-install E2E | `.github/workflows/ci.yml` |
| 25 | Disaster recovery docs | MISSING | COMPLETE as documentation ([DISASTER-RECOVERY.md](DISASTER-RECOVERY.md)); restores can only be rehearsed by the operator | – |
| 26 | Production-readiness CLI | PARTIAL | COMPLETE as an audit (PASS/WARN/FAIL, fails soft when dependencies are down); it never certifies "production ready" | `OpsCommandsTest`, `ops.test.js` |
| 27 | Reference application | MISSING | COMPLETE as an in-process demonstration ([REFERENCE-APP.md](REFERENCE-APP.md)); not a deployable multi-service stack | `CheckoutSagaTest` (7) |
| 28 | Performance measurements | MISSING | PARTIAL: relative overhead measured on a laptop with SQLite; not capacity numbers ([BENCHMARKS.md](BENCHMARKS.md)) | `OverheadBenchmarkTest` (opt-in) |

Design decisions taken from this audit are recorded in [RELIABILITY.md](RELIABILITY.md).
