# Changelog

All notable changes to NestLaravel are documented here. The project follows [Semantic Versioning](https://semver.org/)
and the [Keep a Changelog](https://keepachangelog.com/) format. Upgrade instructions: [UPGRADING.md](UPGRADING.md).

## [Unreleased]

### Added

- `create --db pgsql --migrate` starts the project's Postgres container when Docker is running, on the next free port if
  5432 is taken (written to `POSTGRES_PORT` and the gateway's `DB_PORT`). Stops early if a Docker volume from an
  earlier project of the same name would reject the new database password.

## [1.1.1] — install fixes

### Fixed

- `create --db pgsql|mysql` checks for the matching PHP PDO driver (`pdo_pgsql` / `pdo_mysql`) before scaffolding and names the
  `php.ini` to edit, instead of failing mid-install with "could not find driver".
- `create --migrate` checks that the database port is reachable first and prints the `docker compose` / `artisan migrate`
  commands to run, instead of a stack trace.
- `update` syncs the Kafka kit `routes/` folder (`ops.php` was missing in upgraded workspaces).
- Service template ships `pint.json` excluding the framework-published `config/kafka.php` from lint.

## [1.1.0] — production hardening & reliability

Additive and backward compatible: every new behaviour that could change a running system is **opt-in** (see
[UPGRADING.md § 1.1.0](UPGRADING.md#110--production-hardening--reliability)). What is proven and what is not:
[RELIABILITY.md](RELIABILITY.md) (guarantees ↔ tests) and [RELIABILITY-AUDIT.md](RELIABILITY-AUDIT.md) (before/after).

### Added
- **Transactional inbox** (`EventInbox::process($eventId, $handler)`, table `inbox_events`, `kafka.inbox.enabled`): dedup record and
  business writes commit or roll back together; `inbox:prune`.
- **Concurrency-safe outbox**: statuses pending/processing/published/failed, atomic claim (no double publish), exponential backoff,
  stale-claim recovery, `last_error`, DLQ copy on permanent failure, metrics; `outbox:status [--failed] [--requeue]`.
- **Consumer failure handling**: error classification (transient / fatal / poison), never acknowledges before success, DLQ failure ⇒
  no commit, commit failure tolerated, backoff on broker errors, graceful SIGTERM.
- **Event schema governance**: `EventSchema` / `EventSchemaRegistry`, `HasEventSchema`, `events:list`, `events:check` (compatibility
  gate), envelope fields `event_version`, `causation_id`, `traceparent`, `tenant_id`;
  `nestlaravel generate event <type> --service <svc> [--version N]`.
- **Sagas**: `Saga::define()->step()->compensate()`, persisted state, idempotent start/resume, timeouts, retries, reverse
  compensation, `saga:recover` ([SAGA.md](SAGA.md)).
- **Observability**: JSON log channel `nestlaravel` with correlation/trace/tenant ids and secret redaction, Prometheus `/metrics`
  (bearer token, fail closed), W3C trace propagation, optional OTLP export ([OBSERVABILITY.md](OBSERVABILITY.md)).
- **Health**: `/liveness`, `/startup`, `/readiness`, `/health`; liveness never depends on Kafka/DB/Redis; readiness only on
  `HEALTH_REQUIRED` (default database).
- **Resilience** (gateway → service): connect + total timeouts, retry policy with error classification (only safe or
  `Idempotency-Key` requests), shared-cache circuit breaker, 502/503/504 mapping without internal details.
- **Security**: HMAC secret rotation without downtime (`INTERNAL_SERVICE_SECRET_PREVIOUS`), replay protection fails closed when the
  nonce store is down.
- **Degraded modes**: `SafeCache`, `Transactions::idempotent/once`, per-session DB statement timeout.
- **Tenancy**: tenant id in every log line, strict mode for tenant-less jobs, `tenant:check` audit.
- **CLI**: `production:check` (PASS/WARN/FAIL, exit 1 on FAIL, never says "production ready"), `events:list|check`, `kafka:health`,
  `outbox:status`, `dlq:list`, `tenant:check`, `generate event`, `doctor` inspects workspace apps and pcntl.
- **Operations**: Kubernetes reference manifests (`infrastructure/k8s`, validated in CI), `stop_grace_period`, runbook,
  disaster-recovery and failure-scenario guides, a checkout **reference application** ([REFERENCE-APP.md](REFERENCE-APP.md)) and
  opt-in overhead benchmarks ([BENCHMARKS.md](BENCHMARKS.md)).
- **CI**: multi-process inbox concurrency on real PostgreSQL and MySQL, graceful-shutdown tests with pcntl on Linux,
  manifest validation.
- `nestlaravel update` migration `1.1.0`.

### Changed
- Consumer idempotency uses the inbox when `KAFKA_INBOX_ENABLED=true` (default `false` = the 1.0 cache check, unchanged).
- `docker-compose.yml` uses the structured log channel, enables the inbox and sets `stop_grace_period: 40s`.

### Fixed
- Two outbox publishers could publish the same row; the cache-based idempotency check was not atomic with the business
  transaction (duplicate effects after a crash, cache flush or concurrent duplicate).

## [1.0.0] — first public release

### Added
- **`nestlaravel` CLI** (`packages/cli`, zero runtime dependencies, Node ≥ 20.11): `create`, `generate service |
  kafka-event | kafka-topic`, `add tenancy`, `dev`, `test`, `lint`, `build`, `doctor`, `update`.
- **`nestlaravel/kafka`** package (`packages/laravel-kafka`): standard event envelope, transactional outbox
  (`messaging:outbox-publish --daemon`), hardened rdkafka producer and **new rdkafka consumer** with manual commits,
  retry/backoff, poison-message + schema-version dead-lettering, idempotent handling, graceful shutdown.
- **`nestlaravel/tenancy`** package (`packages/laravel-tenancy`): optional single-database multi-tenancy with
  cross-tenant attack tests (Eloquent, queues, Kafka, cache keys, storage paths).
- Service-to-service trust: HMAC-signed gateway → service calls (`GatewaySigner`, `VerifyGatewaySignature`),
  per-service secrets, replay protection, fail-closed services, `/ready` probe, correlation-id propagation.
- Update system with managed-file tracking, backups and idempotent migrations (`nestlaravel update`, `--adopt` for
  pre-CLI workspaces).
- Shared production Dockerfile (`infrastructure/docker/laravel.Dockerfile`: PHP 8.4, rdkafka, redis, nginx),
  `docker-build.mjs`, `lint` and `build` Nx targets on every Laravel project.
- Documentation set, GitHub Actions CI and release pipeline, clean-install E2E test.
- `laravel/mcp` declared as a dependency of `apps/api` (`routes/ai.php` referenced it but it was missing, so the MCP
  tests and endpoints failed).

### Changed
- Dev infrastructure: **Kafka KRaft** (`apache/kafka:4.0.0`) replaces Confluent + ZooKeeper; MinIO/Mailpit moved to the
  optional `tools` profile (Mailpit) or removed (MinIO); images pinned; ports bound to `127.0.0.1`; Redis requires a
  password; `docker-compose.infra.yml` is now standalone and included by `docker-compose.yml`.
- Dependencies: `laravel/framework` 13.24 → 13.34, `league/commonmark`, `league/flysystem` (security), `guzzlehttp/*`
  (via framework), Nx `^21.6` → `^23.2`; PHP minimum documented as 8.3 (tested 8.4).
- `Users` is no longer a reserved service name (`generate service users` works).
- Event envelope gains `source` (alias of `producer`) and optional `tenant_id`.
- Sanctum tokens expire after 24 h by default (`SANCTUM_TOKEN_EXPIRATION`).
- Code style: Laravel Pint applied to `apps/api`.

### Security
- **Critical** — `POST /api/auth/register` accepted a client-chosen `role`, allowing anyone to self-register as
  `platform_admin`. Now restricted to `auth.self_registration_roles` (default `customer`), enforced in the request and
  the action; regression tests added.
- **Critical** — gateway proxy routes were unauthenticated and downstream services had no authentication at all.
  Proxy now requires `auth:sanctum` + throttling and signs every call; services verify or reject (401/503).
- **High** — the outbox marked messages *published* before the broker confirmed delivery (message loss on broker
  failure). Rows are now marked only after `flush()` succeeds; delivery-report errors surface.
- **High** — dead-letter publish failures were swallowed and the offset acknowledged (message loss). Now the offset is
  not committed.
- **High** — no production Kafka consumer existed and the producer had no TLS/SASL, `acks=all` or idempotence. Added.
- **High** — vulnerable dependencies (`league/commonmark` DoS, Laravel debug-page XSS, Flysystem path check) updated;
  `composer audit` is clean.
- **Medium** — gateway forwarded the user's bearer token to internal services; `/api/gateway/services` exposed
  internal URLs unauthenticated; encoded `..`/control characters in proxied paths; redirects followed (SSRF pivot);
  auth throttling was per-IP only; missing `mcp` rate limiter caused HTTP 500s; committed default credentials
  (`secret`, `minioadmin`, a fixed `APP_KEY` in `docker-compose.test.yml`); `APP_DEBUG=true` in `.env.example`.
- **Low** — `/health` disclosed the Kafka broker address.

### Breaking changes
See [UPGRADING.md § 1.0.0](UPGRADING.md#100-first-cli-release--what-changes-for-existing-projects). In short: direct
calls to services now need the gateway signature; proxy routes require authentication; the gateway no longer
forwards bearer tokens; compose files were restructured; registration role escalation must be closed in existing
apps.

### Migration
`npx nestlaravel update --adopt --dry-run`, then `npx nestlaravel update --adopt`.

[Unreleased]: https://github.com/INTELFRIC/NestLaravel/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/INTELFRIC/NestLaravel/releases/tag/v1.0.0
