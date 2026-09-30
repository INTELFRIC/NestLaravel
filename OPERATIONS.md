# Operations runbook

How to run NestLaravel services in production and how to check that they are healthy. Pair with
[RELIABILITY.md](RELIABILITY.md) (guarantees), [OBSERVABILITY.md](OBSERVABILITY.md) (signals) and
[DISASTER-RECOVERY.md](DISASTER-RECOVERY.md).

## 1. Process model

| Process | Command | Replicas | Notes |
|---------|---------|----------|-------|
| HTTP (per service + gateway) | php-fpm + nginx | ≥ 2, stateless | gateway state (rate limit, circuit breaker, nonces) lives in the shared cache, so `CACHE_STORE` must be `redis` when replicas > 1 |
| Outbox publisher | `php artisan messaging:outbox-publish --daemon` | 1 per service (strict ordering) — more are safe but may reorder one aggregate's events | one deployment per service |
| Kafka consumer | `php artisan kafka:consume <topic> <Handler> --max-runtime=3600 --memory=200` | ≤ partitions of the topic, same `KAFKA_GROUP_ID` | scale on **consumer lag**, not CPU |
| Saga recovery | `php artisan saga:recover` every minute | 1 (scheduler) | only for services that define sagas |
| Inbox retention | `php artisan inbox:prune` daily | 1 | retention must exceed your longest redelivery window |

Reference manifests: `infrastructure/k8s/{service,workers,gateway}.yaml` (validated with kubeconform in CI; **not**
deployed to a cluster by CI). Compose: `docker-compose.yml` (`stop_grace_period` set for workers).

### Settings production should have (`nestlaravel production:check` reports on these)

`APP_ENV=production`, `APP_DEBUG=false`, `KAFKA_ENABLED=true`, `KAFKA_SECURITY_PROTOCOL=ssl|sasl_ssl` (FAIL if plaintext in
production), producer `acks=all` + idempotence (FAIL otherwise), `KAFKA_USE_OUTBOX=true`, `KAFKA_INBOX_ENABLED=true`
(the library default is `false` for upgrade compatibility – see [UPGRADING.md](UPGRADING.md); the generated Compose stack
turns it on), `KAFKA_SCHEMA_ENFORCE_PRODUCER=true` and `KAFKA_SCHEMA_ENFORCE_CONSUMER=true` once every event has a
schema, `CACHE_STORE=redis` (shared), `DB_STATEMENT_TIMEOUT_MS` > 0, `METRICS_TOKEN` set, `INTERNAL_SERVICE_SECRET` ≥ 32
chars, `LOG_CHANNEL=nestlaravel`. Things it can only remind you about (broker ACLs, backups, TLS termination) are reported
as WARN "not verifiable".

## 2. Health probes

| Probe | Path | Failing means | Orchestrator action |
|-------|------|---------------|---------------------|
| startup | `/startup` | database unreachable or reliability tables not migrated | keep waiting (do not send traffic) |
| liveness | `/liveness` | the PHP process cannot answer | restart. Never depends on Kafka/DB/Redis, so an outage cannot cause a restart storm |
| readiness | `/readiness` | a dependency in `HEALTH_REQUIRED` (default `database`) is down | remove from load balancer |
| full | `/health` | JSON per dependency; `degraded` (200) vs `down` (503) | dashboards / humans |

The gateway keeps its own `/health/live` and `/health/ready`.

## 3. Graceful shutdown

* **Consumer** – on SIGTERM finishes the message in flight, commits its offset, leaves the consumer group (fast
  rebalance), closes DB/Redis, exits 0. New messages are not started. (`GracefulShutdownTest`, Linux/pcntl.)
* **Outbox daemon** – finishes and records the batch in progress, exits 0. (`GracefulShutdownTest`.)
* **HTTP** – nginx/php-fpm finish in-flight requests; the reference manifests use a `preStop` delay so the endpoint is
  removed from the Service before nginx stops. Not exercised by automated tests.
* Set `terminationGracePeriodSeconds` (60 in the manifests) above your slowest message/request. A SIGKILL is still safe
  for data (inbox + outbox make replays harmless) but is slower to recover from (rebalance timeout, visibility timeout).

## 4. Deploying

1. **Migrations first, additive only** (expand → deploy → contract). Never rename/drop a column in the release that stops using it.
2. Roll consumers before producers when an event schema gains a version; raise `KAFKA_EVENT_MAX_VERSION` only after
   all consumers understand it (`php artisan events:check` gates compatibility).
3. Rolling update with `maxUnavailable: 0`. Wait for `/readiness` before shifting traffic.
4. Verify: `nestlaravel production:check`, `nestlaravel outbox:status`, `nestlaravel kafka:health`.

### Rotating the service-to-service secret without downtime

1. Set `INTERNAL_SERVICE_SECRET_PREVIOUS=<old>` and `INTERNAL_SERVICE_SECRET=<new>` on **every service** (services accept
   both, verifying the new one first). Roll them out.
2. Switch the gateway to sign with `<new>`. Roll out.
3. After all gateway replicas run the new value, remove `INTERNAL_SERVICE_SECRET_PREVIOUS` from services.

(`GatewaySignatureTest`: rotation, replay and expiry cases.)

## 5. Day-2 commands

| Question | Command |
|----------|---------|
| Are events leaving the DB? | `nestlaravel outbox:status` (pending, processing, failed, oldest pending age) |
| Rows stuck in `failed`? | `nestlaravel outbox:status --failed`, fix cause, then `--requeue` |
| Poison messages parked? | `nestlaravel dlq:list <topic>` |
| Is the broker reachable? | `nestlaravel kafka:health` |
| Which events/versions exist? | `nestlaravel events:list`, `nestlaravel events:check` |
| Any cross-tenant exposure? | `nestlaravel tenant:check` |
| Is this configuration sane? | `nestlaravel production:check` (exit 1 on FAIL) |

Replaying a dead letter: fix the root cause, then re-publish the original payload to the source topic; the inbox makes
this safe even if part of it had been processed.

## 6. Scaling

* HTTP: add replicas. Database connections are the usual limit (`replicas × FPM children`) – size a pooler (pgBouncer).
* Consumers: replicas ≤ partitions. To go beyond, increase partitions *before* the topic is used with keyed ordering
  requirements (changing partition count changes key→partition mapping).
* Outbox: one publisher is normally enough (batch of `KAFKA_OUTBOX_BATCH_SIZE`, default 100, per cycle). If
  `oldest_pending_age` grows, first check broker health, then raise batch size, then add publishers accepting weaker ordering.

## 7. Failure drills (run them in staging; the framework cannot run them for you)

Each drill: cause it, observe the listed signal, confirm the expected outcome, recover.

| Drill | Expected |
|-------|----------|
| Stop the Kafka broker for 5 min while creating orders | HTTP 2xx continues; `outbox_pending` and `oldest_pending_age` grow; after restart backlog drains, no missing or duplicate business effects |
| `kill -9` a consumer during load | rebalance, redelivery, inbox `duplicate` counter increments, no double effects |
| Stop Redis | caches degrade; replay protection answers 503 (fail closed); breaker fails open; alert on `nestlaravel_cache_errors_total` |
| Stop the database | `/readiness` 503, `/liveness` 200; recovery without restarts |
| Publish a malformed message | lands on `<topic>.dlq`, partition keeps moving |
| Deploy a schema-incompatible event | `events:check` fails the pipeline |

Automated equivalents of the in-process versions of these exist in `ChaosScenariosTest`; they use an in-memory broker
and SQLite, so they prove the *logic*, not your infrastructure.
