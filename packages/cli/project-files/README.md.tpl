# {{name}}

A [NestLaravel](https://nestlaravel.dev) workspace: **Nx** (monorepo) + **Laravel** microservices + **Kafka** events,
behind a single public API gateway.

```
apps/api                  PUBLIC gateway (auth, users, request routing)
apps/<name>-service       INTERNAL Laravel microservices (generated)
packages/laravel-kafka    Kafka kit shared by every Laravel app (events, outbox, DLQ, idempotency)
infrastructure/           Dockerfile, compose helpers, Postgres init
```

## Everyday commands

```bash
npx nestlaravel dev                       # start Kafka/Postgres/Redis (Docker) + every app
npx nestlaravel generate service orders   # new Laravel microservice, wired into gateway/Nx/Docker
npx nestlaravel generate kafka-event order.created --service orders
npx nestlaravel test                      # all tests (Nx)
npx nestlaravel build                     # production Docker images
npx nestlaravel doctor                    # verify your machine
npx nestlaravel update                    # upgrade framework files safely
```

## Security notes

* `.env` files are git-ignored and were generated with fresh random secrets. Never commit them.
* Microservices accept traffic only from the gateway (HMAC-signed). Do **not** publish their ports.
* See the NestLaravel docs: SECURITY.md, DEPLOYMENT.md, KAFKA.md.
