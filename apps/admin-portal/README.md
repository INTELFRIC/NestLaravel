# Admin Portal

Next.js App Router UI for platform operators. Authenticates against the Laravel API with Sanctum Bearer tokens.

## Run

```bash
cd apps/admin-portal
npm install
npm run dev
```

Dev server: **http://localhost:3001**

From the workspace root (after `npm install`), together with the API and services:

```bash
npx nestlaravel dev
```

The portal is an Nx project with a `serve` target, so no separate terminal is needed. Missing from a workspace? `npx nestlaravel add portals`.

## Config

Copy `.env.local.example` to `.env.local` if needed:

```
NEXT_PUBLIC_API_URL=http://localhost:8000/api
```

Requires the API running at `http://localhost:8000`.
