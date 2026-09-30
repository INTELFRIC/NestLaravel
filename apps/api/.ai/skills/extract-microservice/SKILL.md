---
name: extract-microservice
description: Extracts a local Laravel module into an independent microservice behind the API gateway. Use when extracting a service, enabling GATEWAY_*_ENABLED, splitting the monolith, or moving Orders/Payments out of apps/api.
---

# Extract a microservice

Default mode is **one modular monolith**. Extract only when the module owns its tables, events are versioned, and boundaries are clean.

## Preconditions

- No shared Eloquent across modules
- Module owns its migrations/tables
- Integration events have stable `event_type` + version
- Auth stays on the gateway

## Steps

1. Build as a module first (`make-module` skill)
2. `cd apps/api && php artisan make:microservice {Name}` — Laravel app under `apps/{name}-service`
3. Share thin `Core` / messaging contracts (Composer path repo or copy)
4. Own `.env`, database, workers, Kafka consumer group, `/health`
5. Register in `apps/api/config/gateway.php`:

```env
GATEWAY_ORDERS_ENABLED=true
ORDERS_SERVICE_URL=http://orders-service:8000
```

6. Remove local routes for that prefix so the proxy owns `/api/v1/{prefix}/*`
7. Prefer Kafka for fan-out; HTTP proxy only when the client needs a synchronous response

## Rules

- Portals never call the new service URL
- Do not chain gateway → A → B → C for one request
- Trust forwarded `Authorization` + correlation headers

Verify with MCP `list-gateway-services`. See [docs/microservices.md](../../../docs/microservices.md) and [docs/gateway.md](../../../docs/gateway.md).
