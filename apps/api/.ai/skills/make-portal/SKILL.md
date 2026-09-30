---
name: make-portal
description: Adds a Next.js UI portal that authenticates with Sanctum and talks only to the API gateway. Use when creating a portal, frontend app, customer-portal, admin-portal, or NestJS-style UI.
---

# Make a UI portal

Portals are Next.js apps under `apps/`. Backend stays in Laravel modules.

## Steps

1. Scaffold:

```bash
npx create-next-app@latest apps/partner-portal --ts --eslint --app --src-dir --use-npm
```

2. Add `"partner-portal"` to root `package.json` `workspaces`
3. Depend on `@platform/api-client`
4. Copy `src/lib/api.ts` and the login page from `apps/customer-portal`
5. Pick a unique port (customer `3000`, admin `3001`)
6. Add `apps/{name}/project.json` for Nx
7. Add the origin to `CORS_ALLOWED_ORIGINS` in `apps/api/.env` and `config/cors.php` defaults if needed

## Auth

1. `POST /api/auth/login`
2. Store `data.token` in `localStorage`
3. `GET /api/auth/me` with `Authorization: Bearer …`
4. Logout: `POST /api/auth/logout`

## Rules

- Talk **only** to the gateway (`apps/api`). Never call `orders-service` (or any microservice) from the browser
- Shared HTTP client lives in `packages/api-client`

See [docs/portals.md](../../../docs/portals.md).
