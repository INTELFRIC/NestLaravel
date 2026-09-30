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

# Terminal 2 — install JS workspaces once from repo root
npm install
npm run customer-portal   # :3000
npm run admin-portal      # :3001
```

Or with Nx (after `npm install`):

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
npx create-next-app@latest apps/partner-portal --ts --eslint --app --src-dir --use-npm
```

Copy `src/lib/api.ts` + login page pattern from `customer-portal`, set port, add workspace entry in root `package.json`, and add the origin to `CORS_ALLOWED_ORIGINS`.

## Related

- [Authentication](authentication.md)
- [Microservices](microservices.md)
- [Development](development.md)
