# Admin Portal

Next.js App Router UI for platform operators. Authenticates against the Laravel API with Sanctum Bearer tokens.

## Run

```bash
cd apps/admin-portal
npm install
npm run dev
```

Dev server: **http://localhost:3001**

From the monorepo root (after `npm install`):

```bash
npm run admin-portal
```

## Config

Copy `.env.local.example` to `.env.local` if needed:

```
NEXT_PUBLIC_API_URL=http://localhost:8000/api
```

Requires the API running at `http://localhost:8000`.
