# Developer Guide: Add an Orders Microservice (End-to-End)

This is the recommended way to use this platform effectively — **API gateway + modules + optional microservice extraction + UI portals**.

Audience: backend and frontend developers building on Nest-Laravel.

---

## Mental model (read this once)

```text
UI portals (customer / admin)
        │  ONLY talk to the gateway
        ▼
   apps/api  ← API GATEWAY (Swagger, Auth, proxy)
        │
        ├── Local modules (Auth, Users, …) — in-process
        └── Microservices (orders-service, …) — HTTP proxy + Kafka
```

Rules:

1. **Portals never call microservices directly** — only `http://localhost:8000` (or prod gateway URL).
2. **Build as a module first** inside `apps/api`, then extract when needed.
3. **Auth stays on the gateway** (Sanctum). Services trust the forwarded Bearer token.
4. **Swagger** documents the gateway public API: `http://localhost:8000/docs/api`.

---

## Phase 0 — Prerequisites

```bash
# Infra (Postgres, Redis, Kafka, MinIO, …)
docker compose -f docker-compose.yml -f docker-compose.infra.yml up -d
# or full stack:
docker compose up -d --build

# API
cd apps/api
composer install
cp .env.example .env   # if needed
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force

# Portals (repo root)
cd ../..
npm install
npm run customer-portal   # :3000
npm run admin-portal      # :3001
```

Demo logins (after seed):

| Portal | Email | Password | Role |
|--------|-------|----------|------|
| Customer | `test@example.com` | `password` | `customer` |
| Admin | `admin@example.com` | `password` | `platform_admin` |

Swagger UI: **http://localhost:8000/docs/api**  
OpenAPI JSON: **http://localhost:8000/docs/api.json**

---

## Phase 1 — Create Orders as a local module (on the gateway)

Work inside `apps/api`. This is how every new domain should start.

### Step 1.1 — Scaffold the module

```bash
cd apps/api
php artisan make:module Orders
```

Creates:

```text
app/Modules/Orders/
├── Domain/
├── Application/
├── Infrastructure/
└── Presentation/
```

### Step 1.2 — Register the provider

In `apps/api/bootstrap/providers.php` add:

```php
use App\Modules\Orders\Infrastructure\Providers\OrdersServiceProvider;

return [
    // ...existing
    OrdersServiceProvider::class,
];
```

### Step 1.3 — Generate use-case artifacts

```bash
php artisan make:dto CreateOrderData --module=Orders
php artisan make:action CreateOrder --module=Orders
php artisan make:domain-event OrderCreated --module=Orders
php artisan make:consumer OrderCreatedConsumer --module=Orders
```

### Step 1.4 — Implement the vertical slice

Recommended flow (same pattern for every feature):

```text
POST /api/v1/orders
  → CreateOrderRequest          (Presentation)
  → CreateOrderData             (Application DTO)
  → CreateOrder Action          (Application)
  → OrderRepository contract    (Domain)
  → EloquentOrderRepository     (Infrastructure)
  → orders table
  → OrderCreated event
  → EventBus (Outbox → Kafka when enabled)
  → OrderResource JSON
```

Checklist:

| Layer | What to add |
|-------|-------------|
| Domain | `Order` entity, `OrderRepository` interface, `OrderCreated` event |
| Application | `CreateOrder`, `GetOrder`, `ListOrders` + DTOs |
| Infrastructure | Eloquent model, repository, migration, consumer |
| Presentation | Controller, FormRequest, Resource, `Routes/api.php` |

Example Action shape:

```php
final class CreateOrder
{
    public function __construct(
        private OrderRepository $orders,
        private EventBus $eventBus,
    ) {}

    public function execute(CreateOrderData $data): Order
    {
        $order = $this->orders->create($data);
        $this->eventBus->publish(new OrderCreated($order));

        return $order;
    }
}
```

### Step 1.5 — Migration + migrate

```bash
php artisan make:migration create_orders_table
# edit migration, then:
php artisan migrate
```

### Step 1.6 — Protect routes (optional)

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{id}', [OrderController::class, 'show']);
});
```

### Step 1.7 — Verify on Swagger + HTTP

1. Open http://localhost:8000/docs/api  
2. Authorize with Bearer token from `POST /api/auth/login`  
3. Call `POST /api/v1/orders`

```bash
# login
curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"email\":\"admin@example.com\",\"password\":\"password\"}"

# create order (replace TOKEN)
curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"customer_id\":\"...\",\"total\":100}"
```

At this point Orders is a **local module on the gateway**. Portals can already use it. Stop here unless you need an independent service.

---

## Phase 2 — Wire the UI portal (customer / admin)

Portals live under `apps/*-portal` and use `@platform/api-client`.

### Step 2.1 — API client (already shared)

`packages/api-client` talks to `NEXT_PUBLIC_API_URL` (default `http://localhost:8000/api`).

Add Orders helpers (example):

```ts
// packages/api-client/src/index.ts (extend createApiClient return)
createOrder(payload: { customer_id: string; total: number }) {
  return request('/v1/orders', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
},
listOrders() {
  return request('/v1/orders', { method: 'GET' });
},
```

### Step 2.2 — Customer portal page

```text
apps/customer-portal/src/app/orders/page.tsx
```

Flow:

1. Ensure user is logged in (token in `localStorage`)
2. `api.listOrders()` / `api.createOrder(...)`
3. Never hardcode microservice hosts — only the gateway

### Step 2.3 — Admin portal

Same pattern under `apps/admin-portal` (e.g. manage all orders). Use admin token (`platform_admin`).

### Step 2.4 — CORS

Gateway already allows portals via `CORS_ALLOWED_ORIGINS`:

```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:3001
```

### Step 2.5 — Run UI

```bash
# terminal A — API (Docker or artisan serve)
# terminal B
npm run customer-portal
# terminal C
npm run admin-portal
```

UI → Gateway → Orders module. That is the effective daily workflow.

---

## Phase 3 — Extract Orders into a microservice

Do this only when you need independent deploy/scale/ownership.

### Step 3.1 — Use the service app under `apps/`

The Laravel app already lives at:

```text
apps/orders-service/
```

Move domain code into it:

- `app/Modules/Orders/**` (Domain + Application + needed Infrastructure/Presentation)
- Orders migrations
- Thin Core pieces if required (`EventBus` contract, `AbstractDomainEvent`, `ApiResponse`)

Keep the **same route shape**: `/api/v1/orders`.

Run only this backend:

```bash
npx nx serve orders-service
```

### Step 3.2 — Own database (recommended)

```env
# apps/orders-service/.env
DB_DATABASE=orders
```

Run Orders migrations only on that database.

### Step 3.3 — Docker network

Add `orders-service` to `docker-compose.yml` on `nest-laravel-net`, **no public port in production** (internal only). For local debug you may publish e.g. `8001:80`.

### Step 3.4 — Register on the gateway

`apps/api/.env`:

```env
GATEWAY_ENABLED=true
GATEWAY_ORDERS_ENABLED=true
ORDERS_SERVICE_URL=http://127.0.0.1:8001
```

`config/gateway.php` already has an `orders` entry.

### Step 3.5 — Disable local Orders routes on the gateway

Remove or comment `OrdersServiceProvider` from `bootstrap/providers.php` **after** the proxy is enabled, so `/api/v1/orders/*` is handled by `GatewayProxy`, not the local module.

### Step 3.6 — Auth forwarding

Gateway forwards:

- `Authorization: Bearer …`
- `X-Request-ID` / `X-Correlation-ID`
- `X-Forwarded-By: platform-api-gateway`

Orders-service should accept Sanctum token validation (shared DB for tokens) **or** switch later to internal JWT/introspection. For the starter, shared `personal_access_tokens` / user id in token is enough to begin.

### Step 3.7 — Events (async)

Prefer Kafka for side effects:

```text
CreateOrder (orders-service)
  → OrderCreated
  → Outbox
  → Kafka topic order.events
  → notification-service / analytics consumers
```

Do **not** make the portal call notifications directly.

### Step 3.8 — Verify full path

```text
Admin/Customer UI
  → POST http://localhost:8000/api/v1/orders
  → GatewayProxy
  → http://orders-service:8000/api/v1/orders
  → 201 + OrderCreated → Kafka
```

Swagger still shows the **gateway** contract (`/api/v1/orders`). Clients do not change.

---

## Phase 4 — End-to-end flow checklist (Orders)

Use this as a PR / release checklist.

### Backend (gateway + service)

- [ ] Module scaffolded with Domain / Application / Infrastructure / Presentation  
- [ ] Provider registered (local) **or** gateway proxy enabled (extracted)  
- [ ] Migration applied  
- [ ] Action + DTO + FormRequest + Resource  
- [ ] `OrderCreated` published via `EventBus`  
- [ ] Consumer idempotent (`IdempotencyStore`) if consuming Kafka  
- [ ] Routes under `/api/v1/orders`  
- [ ] Auth middleware where needed  
- [ ] Visible in Swagger (`/docs/api`)  
- [ ] Feature test for create/list/show  

### Gateway

- [ ] `GET /api/gateway/services` shows `orders`  
- [ ] When extracted: `GATEWAY_ORDERS_ENABLED=true`  
- [ ] Local Orders provider removed after extract  
- [ ] Correlation IDs present on proxied requests  

### UI portals

- [ ] Uses `@platform/api-client` / `NEXT_PUBLIC_API_URL`  
- [ ] Login via `/api/auth/login`  
- [ ] Orders screens call **only** gateway `/api/v1/orders`  
- [ ] Customer vs Admin roles enforced (gateway + UI)  

### Ops

- [ ] Health: `/health`, `/health/ready`  
- [ ] Workers: `queue:work` / `messaging:outbox-publish`  
- [ ] Kafka UI for `order.events` (optional)  

---

## Phase 5 — Day-to-day developer commands

```bash
# New capability (always start here)
cd apps/api
php artisan make:module Inventory
php artisan make:action CreateItem --module=Inventory
php artisan make:dto CreateItemData --module=Inventory
php artisan make:domain-event ItemCreated --module=Inventory

# Docs
open http://localhost:8000/docs/api

# Tests
php artisan test

# Gateway registry
curl http://localhost:8000/api/gateway/services

# Portals
npm run customer-portal
npm run admin-portal
```

---

## Anti-patterns (avoid)

| Don’t | Do instead |
|-------|------------|
| Portal → `http://orders-service:8001` | Portal → gateway `/api/v1/orders` |
| Duplicate login in each service | Gateway Auth + forwarded Bearer |
| Kafka for a simple read | Sync HTTP via gateway or local module |
| Put business logic in Controllers | Actions + Domain |
| Share Eloquent models across services | Contracts + events + own DB |
| Skip Swagger | Keep gateway routes documented at `/docs/api` |

---

## Related docs

| Doc | Purpose |
|-----|---------|
| [gateway.md](gateway.md) | Gateway registry & proxy |
| [microservices.md](microservices.md) | Extraction rules |
| [modules.md](modules.md) | Module layout |
| [portals.md](portals.md) | UI apps |
| [authentication.md](authentication.md) | Sanctum |
| [docker.md](docker.md) | Run infra |
| [development.md](development.md) | DX generators |

---

## Quick “happy path” summary

1. `make:module Orders` on **gateway**  
2. Implement Request → DTO → Action → Repo → Event  
3. Call from **Swagger** and from **customer/admin portals** via gateway URL  
4. When needed, extract to `orders-service` and set `GATEWAY_ORDERS_ENABLED=true`  
5. UI keeps the same URLs — only gateway config changes  

That is how to use this framework effectively for API + microservices + UI.
