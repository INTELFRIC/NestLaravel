# Microservices

## Generate one

```bash
npx nestlaravel generate service payments
```

Under the hood this runs the framework's Artisan generator (`php artisan make:microservice Payments`) and then adds
everything Artisan cannot. Result:

```text
apps/payments-service/
├── app/
│   ├── Http/Middleware/VerifyGatewaySignature.php   INTERNAL guard (HMAC, replay-safe, fail-closed)
│   └── Modules/Payments/{Domain,Application,Infrastructure,Presentation}
├── config/{internal.php,kafka.php}                  signing secret + Kafka kit config
├── database/  routes/  tests/  bootstrap/  public/
├── .env / .env.example                              own APP_KEY, INTERNAL_SERVICE_SECRET, KAFKA_* (client/group/topic)
├── composer.json                                    requires nestlaravel/kafka (path package)
└── project.json                                     Nx targets: serve · test · lint · migrate · build
```

Plus, in the workspace:

| Where | Change |
|-------|--------|
| `apps/api/config/gateway.php` | `payments` entry: prefix, base URL, timeout, `secret`, `public=false` |
| `apps/api/.env` | `PAYMENTS_SERVICE_URL`, `PAYMENTS_SERVICE_SECRET` (same value as the service's `INTERNAL_SERVICE_SECRET`), `GATEWAY_PAYMENTS_ENABLED=true` |
| `docker-compose.yml` | `payments-service` container **without published ports** + `payments-service-outbox` (Kafka publisher daemon) |
| `infrastructure/postgres/initdb/20-payments.sh` | dedicated Postgres role + database (`--db pgsql`) |
| root `.env` | `PAYMENTS_SERVICE_SECRET`, `PAYMENTS_DB_PASSWORD` for Compose |
| `nestlaravel.json` | service listed for `update` |

Public path: `/api/v1/payments/*` on the gateway → `http://payments-service/api/v1/payments/*` (signed).

## Service contract (what makes a service independent)

```text
Service
├── API               routes under /api/v1/<name>, reachable only through the gateway
├── Business logic    Modules/<Name>/{Domain,Application}
├── Database          its own (sqlite locally, its own Postgres role+database in Compose/production)
├── Configuration     its own .env, APP_KEY, secrets — nothing shared with siblings
├── Kafka             nestlaravel/kafka: outbox, producer, consumer pipeline, DLQ, idempotency
├── Tests             tests/Feature (gateway signature, Kafka kit), add your own
└── Deployment        `nx build payments-service` → its own Docker image; deploy/scale/rollback independently
```

Rules: no shared Eloquent models, no shared tables, no cross-service DB users, no shared secrets. Integrate through
events ([KAFKA.md](KAFKA.md)) or the gateway API.

## Choosing sync vs async

| Use HTTP via the gateway when… | Use a Kafka event when… |
|-------------------------------|-------------------------|
| the caller needs the answer now (queries, validations) | something *happened* and others may react (order placed) |
| the operation is user-driven | the reaction can be eventually consistent (emails, stock, analytics) |
| | you want retries/DLQ and replay for free |

Avoid service → service HTTP calls; if two services chat synchronously all the time, the boundary is probably wrong.

## Local ports

Gateway `8000`; services from `8001` upward (`--port` to choose). Nx: `npx nx serve payments-service`.
