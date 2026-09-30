# Changelog

All notable changes to NestLaravel are documented here. The project follows [Semantic Versioning](https://semver.org/)
and the [Keep a Changelog](https://keepachangelog.com/) format. Upgrade instructions: [UPGRADING.md](UPGRADING.md).

## [Unreleased]

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
