> **NestLaravel 1.0 update:** proxy routes require `auth:sanctum`, every call to a service is HMAC-signed (per-service `secret`), bearer tokens are not forwarded and `forward_auth` defaults to `false`. Services reject unsigned calls. See [SECURITY.md](../SECURITY.md) and [ARCHITECTURE.md](../ARCHITECTURE.md#request-flow).

# API Gateway

**`apps/api` is the platform API Gateway.**

Portals and external clients talk **only** to the gateway. New microservices are not exposed publicly; they are registered behind the gateway and reached by proxy (sync) or Kafka (async).

```text
Portals / mobile / partners
            │
            ▼
     apps/api  (GATEWAY)
     ├── Auth / Users (local modules — identity stays here)
     ├── /api/v1/orders/**      → orders-service      (when enabled)
     ├── /api/v1/payments/**    → payments-service    (when enabled)
     └── /api/v1/notifications/** → notifications-service
            │
            ├── HTTP proxy (sync)
            └── Kafka / Outbox (async events)
```

## Responsibilities of the gateway

| Concern | Where |
|---------|--------|
| Public HTTP entry | `apps/api` only |
| Login / tokens / roles | Auth module (local) |
| Correlation / request IDs | Gateway middleware |
| Route to microservice | `config/gateway.php` + `GatewayProxy` |
| Business domain after extract | Downstream Laravel service |

## Local vs remote

1. **Start local** — implement a module inside `apps/api` (`php artisan make:module Orders`).
2. **Extract later** — move the module to `apps/orders-service` (or a new repo).
3. **Register in gateway** — set `GATEWAY_ORDERS_ENABLED=true` and `ORDERS_SERVICE_URL=...`.
4. **Remove local routes** for that prefix so the proxy owns `/api/v1/orders/*`.

## Register a microservice

Edit `apps/api/config/gateway.php` (or env):

```env
GATEWAY_ORDERS_ENABLED=true
ORDERS_SERVICE_URL=http://127.0.0.1:8001
# Docker DNS: http://orders-service:8000
```

Public call:

```http
GET /api/v1/orders/123
Authorization: Bearer {sanctum-token}
```

Gateway forwards to:

```http
GET http://orders-service:8000/api/v1/orders/123
Authorization: Bearer {same-token}
X-Correlation-ID: …
X-Request-ID: …
X-Forwarded-By: platform-api-gateway
```

## Inspect registry

```http
GET /api/gateway/services
```

Lists configured services and whether each proxy is enabled.

## Rules

1. Portals never call microservice URLs directly.
2. Auth stays on the gateway; services trust the forwarded Bearer token (or later mTLS / internal JWT).
3. Prefer Kafka for fan-out; use gateway HTTP proxy only when the client needs a synchronous response.
4. Do not chain gateway → A → B → C for one request.

## Related

- [Microservices](microservices.md)
- [Authentication](authentication.md)
- [Portals](portals.md)
