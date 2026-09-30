# Architecture

## The four building blocks

| Block | Role in NestLaravel | Where |
|-------|--------------------|-------|
| **Nx** | Monorepo & orchestration: project graph, cached/affected `test`/`lint`/`build`, one command surface | `nx.json`, `apps/*/project.json` |
| **Laravel** | The application runtime. The **gateway** and **every microservice** are Laravel apps | `apps/api`, `apps/<name>-service` |
| **Kafka** | Asynchronous integration between services (domain events). Services never read each other's databases | `packages/laravel-kafka` |
| **CLI** | The framework layer developers touch: scaffold, generate, develop, upgrade | `packages/cli` (`nestlaravel`) |

### What about NestJS?

The repository this framework grew out of describes its gateway as "NestJS-style", but **it contains no NestJS code**:
the gateway is Laravel (`apps/api`), and the tooling layer is plain Node.js. That was audited, kept, and made
explicit rather than adding NestJS for its own sake:

* the backend microservices are Laravel by design — introducing NestJS microservices would duplicate them;
* an extra Node gateway would add a hop, a second auth stack and a second place to get security wrong;
* the CLI needs no framework: it is a small, dependency-free Node program (nothing to patch, fast `npx`).

The "NestJS-style" ideas that *are* implemented are the module structure (`Domain / Application / Infrastructure /
Presentation`), dependency-injected contracts, and a single gateway in front of the services. If you later want a
NestJS BFF for a specific frontend, it plugs in as another consumer of the gateway (or as an Nx app) — nothing
here prevents it.

## Interfaces

```text
PUBLIC              apps/api                Sanctum tokens, throttling, CORS allow-list, security headers
INTERNAL            apps/<name>-service     no published ports; HMAC-signed gateway calls only
SERVICE-TO-SERVICE  gateway → service       HTTP + HMAC (method, path+query, body hash, user, tenant, ts, nonce)
                    service → service       Kafka events (envelope below) — never direct DB access
ADMIN               php artisan / Kafka UI  not internet-facing; tenancy bypass is explicit (`withoutTenancy`)
```

## Request flow

1. Client calls `POST /api/v1/orders/…` on the **gateway**.
2. Gateway: `auth:sanctum` → `throttle:api` → path sanitisation (no `..`, control chars, encoded separators).
3. Gateway signs the call (`X-Gateway-Timestamp/Nonce/User/Tenant/Signature`) with **that service's own secret**
   and forwards it; redirects are not followed; the user's bearer token is **not** forwarded.
4. Service middleware `VerifyGatewaySignature` verifies freshness (60 s), one-time nonce, and HMAC; otherwise 401.
   With no secret configured it answers 503 (fail closed).
5. The service runs its Action, writes to **its own database**, and publishes domain events through the **outbox**
   (same DB transaction).
6. `messaging:outbox-publish --daemon` ships events to Kafka after broker confirmation; other services consume,
   deduplicate, and react.

## Inside a service

```text
app/Modules/<Name>/
  Domain/          entities, value objects, contracts, domain events   (no framework dependencies)
  Application/     Actions, DTOs, queries                              (controllers call Actions only)
  Infrastructure/  Eloquent repositories, Kafka handlers, providers
  Presentation/    routes, controllers, requests, resources
```

Cross-module access uses contracts or events, never another module's Eloquent models.

## The event contract

```json
{
  "event_id": "uuid",            "event_type": "orders.order.created",
  "version": 1,                  "occurred_at": "2026-09-30T00:00:00+00:00",
  "source": "orders-service",    "producer": "orders-service",
  "aggregate_id": "1001",        "aggregate_type": "order",
  "correlation_id": "uuid",      "causation_id": null,
  "tenant_id": "acme",           "payload": {}
}
```

This is the framework's pre-existing envelope (richer than the minimal one in the brief) plus `source`
(alias of `producer`, the name used by the wider ecosystem) and the optional `tenant_id`. Key = `aggregate_id`, so
all events for one aggregate are ordered within a partition. See [KAFKA.md](KAFKA.md).

## Data ownership

Every service has its **own database and credentials** (`generate service` creates a per-service Postgres role +
database and a separate `APP_KEY`, signing secret and Kafka client/group ids). A service may keep read-models built
from events; it may not query another service's tables.

## Dependency direction (enforced by review and `tests/Architecture`)

`Presentation → Application → Domain ← Infrastructure`. Nx tags (`type:microservice`, `domain:<name>`) are on every
project so `@nx/enforce-module-boundaries`-style rules can be added when the workspace grows TypeScript libraries.

## Known trade-offs / audit notes

* The gateway keeps its own copy of the Kafka classes (`app/Infrastructure/Kafka`, `app/Messaging`); services use the
  shared `nestlaravel/kafka` package. Migrating the gateway to the package is planned for 1.x (pure namespace change).
* Outbox publishing assumes **one publisher per service** (rows are not claimed across workers). Scale consumers, not
  publishers.
* Portals keep the API token in `localStorage`; see [SECURITY.md](SECURITY.md) for the recommended hardening.
