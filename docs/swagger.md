# Swagger / OpenAPI (API Gateway)

Interactive API docs are powered by **[Scramble](https://scramble.dedoc.co/)** (OpenAPI 3 + Stoplight Elements UI).

## URLs

| URL | What |
|-----|------|
| http://localhost:8000/docs/api | Swagger-style UI |
| http://localhost:8000/docs/api.json | OpenAPI JSON |

## Auth in the UI

1. `POST /api/auth/login` (Try it) → copy `data.token`
2. Click **Authorize** → paste token as Bearer
3. Call protected endpoints (`/api/auth/me`, `/api/v1/...`)

## What is documented

Routes under the `api` path prefix, including:

- Auth / Users (local gateway modules)
- Health endpoints (if under documented paths)
- Gateway-proxied microservice routes when registered

## Docker

Rebuild the API image after installing Scramble so the container has the package:

```bash
docker compose up -d --build app
```

Then open http://localhost:8000/docs/api

## Related

- [Adding Orders microservice (step-by-step)](adding-orders-microservice.md)
- [Gateway](gateway.md)
