# Microservices

**`apps/api` is always the API Gateway.** Microservices are optional extractions behind it — portals never call them directly.

```text
customer-portal / admin-portal
            │
            ▼
        apps/api  ←── single public gateway
            │
            ├── local modules (Auth, Users, …)
            └── proxied services (Orders, Payments, …)
```

## Default path (recommended)

1. Build the capability as a **module** inside the gateway (`php artisan make:module Orders`).
2. Keep Auth centralized on the gateway.
3. Extract to a microservice only when scale / team ownership requires it.
4. Enable the gateway proxy in `config/gateway.php` / `.env`.

See [gateway.md](gateway.md).

## Portability rule

Domain + Application code should move with minimal changes. Infrastructure and Presentation adapters may change (HTTP via gateway proxy instead of in-process binding, Kafka instead of local listeners).

## Extraction checklist

1. **Boundaries clear** — module already communicates via contracts/events only
2. **Data ownership** — module owns its tables; no silent shared writes
3. **Events stable** — integration event types and payloads versioned
4. **Auth** — gateway issues Sanctum tokens; service accepts forwarded Bearer (or internal JWT)
5. **Ops** — own deploy, migrations, workers, consumers, health checks
6. **Gateway registry** — `GATEWAY_{SERVICE}_ENABLED=true` + `*_SERVICE_URL`

## Target shape

```text
apps/
├── api/                      # GATEWAY (public)
├── orders-service/           # internal backend (:8001)
├── payments-service/         # internal backend (:8002)
├── notifications-service/    # internal backend (:8003)
├── customer-portal/          # UI → api only
└── admin-portal/             # UI → api only
```

All microservices live under `apps/{name}-service`. Auth and Users stay on the gateway.

### Run one microservice

```bash
npx nx serve orders-service          # :8001
npx nx serve payments-service        # :8002
npx nx serve notifications-service   # :8003
```

Enable only that service on the gateway (`apps/api/.env`):

```env
GATEWAY_ORDERS_ENABLED=true
ORDERS_SERVICE_URL=http://127.0.0.1:8001
```

Each extracted service is a Laravel app with:

- Its module(s)
- Shared Core / messaging contracts when needed
- Own `.env`, database (when required), workers, Kafka consumer group
- **No public port** in production (only reachable from the gateway network)

## Communication

| Need | Mechanism |
|------|-----------|
| Client → platform | HTTPS → **gateway** (`apps/api`) |
| Gateway → service (sync) | HTTP proxy (`GatewayProxy`) |
| Async reaction | Kafka integration events |
| Critical publish | Outbox → Kafka |

Avoid deep synchronous chains:

```text
Bad:  Client → Gateway → A → B → C → D
Good: Client → Gateway → A; A publishes; B/C/D consume
```

## Add a new microservice under `apps/`

Every backend service lives at `apps/{name}-service`. Auth stays on the gateway.

### One command (recommended)

```bash
cd apps/api
php artisan make:microservice Inventory --port=8004
```

From the repo root:

```bash
npm run make:microservice -- Inventory --port=8004
```

That creates `apps/inventory-service`, an Nx `serve` target, and a **disabled** gateway entry.

### Then run these commands

```bash
cd apps/inventory-service
composer install
cp .env.example .env
php artisan key:generate

php artisan make:action CreateInventory --module=Inventory
php artisan make:dto CreateInventoryData --module=Inventory
```

```bash
npx nx serve inventory-service
```

Health: http://127.0.0.1:8004/up

Turn on the proxy only when the service is running:

```env
# apps/api/.env
GATEWAY_INVENTORY_ENABLED=true
INVENTORY_SERVICE_URL=http://127.0.0.1:8004
```

```bash
npx nx serve api
```

Clients still call `http://localhost:8000/api/v1/inventory/...` — never the microservice port.

### Manual equivalent (if you do not use the generator)

```bash
# from repo root
composer create-project laravel/laravel apps/inventory-service

cd apps/inventory-service
composer install
cp .env.example .env
php artisan key:generate
php artisan make:module Inventory
```

Then add `apps/inventory-service/project.json` (copy `apps/orders-service/project.json`, change the name and `--port=`), register the provider in `bootstrap/providers.php`, and add the service to `apps/api/config/gateway.php`.


## Related

- [Gateway](gateway.md)
- [Architecture](architecture.md)
- [Modules](modules.md)
- [Events](events.md)
- [Kafka](kafka.md)
