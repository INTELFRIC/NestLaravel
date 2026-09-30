# Customer Portal

Next.js App Router UI for customers. Authenticates against the Laravel API with Sanctum Bearer tokens.

## Run

```bash
cd apps/customer-portal
npm install
npm run dev
```

Dev server: **http://localhost:3000**

From the monorepo root (after `npm install`):

```bash
npm run customer-portal
```

## Config

Copy `.env.local.example` to `.env.local` if needed:

```
NEXT_PUBLIC_API_URL=http://localhost:8000/api
```

Requires the API running at `http://localhost:8000`.
