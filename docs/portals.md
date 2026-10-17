# UI Portals (NestJS-style apps)

Portals are **frontend applications**. Backend capabilities stay in Laravel modules under `apps/api`.

## Layout

```text
apps/
├── api/                      Laravel 13 API gateway
├── orders-service/           internal backend
├── payments-service/         internal backend
├── notifications-service/    internal backend
├── customer-portal/          Next.js (port 3000)
└── admin-portal/             Next.js (port 3001)
packages/
└── api-client/               Shared Sanctum fetch client (@platform/api-client)
```

## Run locally

```bash
# Terminal 1 — API
cd apps/api
php artisan serve

# Install JS workspaces once from repo root
npm install

# Start everything (API, services, portals) — portals are Nx projects with a `serve` target
npx nestlaravel dev
```

No extra terminals needed: `nestlaravel dev` serves every app that has a `serve` target, including
`customer-portal` (:3000) and `admin-portal` (:3001). Workspace created without `--with-portals`?
Run `npx nestlaravel add portals`.

Or with Nx directly (after `npm install`):

```bash
npx nx serve api
npx nx serve customer-portal
npx nx serve admin-portal
```

## Auth flow

1. Portal posts to `POST /api/auth/login`
2. Stores `data.token` in `localStorage`
3. Calls `GET /api/auth/me` with `Authorization: Bearer …`
4. Logout via `POST /api/auth/logout`

CORS origins are configured in `apps/api/config/cors.php` via `CORS_ALLOWED_ORIGINS`.

## Add another portal

```bash
npx nestlaravel generate app web partner-portal   # Next.js on the next free port (3002+), with serve target, gateway URL and CORS
```

It writes `apps/partner-portal` (with `project.json`), adds the workspace entry, allows its origin in
`CORS_ALLOWED_ORIGINS` (restart the gateway) and `nestlaravel dev` starts it. Copy the login page pattern from `customer-portal`.

## Related

- [Authentication](authentication.md)
- [Microservices](microservices.md)
- [Development](development.md)
