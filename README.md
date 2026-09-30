# NestLaravel

**The Nx + Laravel microservice framework with Kafka.**

NestLaravel gives you a monorepo where every backend microservice is a real **Laravel** application, all of them
sit behind one hardened **API gateway**, and they talk to each other through **Apache Kafka** using a single,
versioned event contract. **Nx** orchestrates builds, tests and the dependency graph; the **`nestlaravel` CLI**
scaffolds and upgrades everything.

```bash
npx nestlaravel create my-project
cd my-project
npx nestlaravel generate service orders
npx nestlaravel dev
```

## Why NestLaravel

| You want | NestLaravel gives you |
|----------|-----------------------|
| Laravel's productivity per service | Each service is a normal Laravel app with a Domain / Application / Infrastructure / Presentation module |
| One front door | `apps/api` is the only public interface: authentication, rate limiting, routing |
| Services that cannot be bypassed | Gateway → service calls are HMAC-signed; services reject anything else (fail closed) |
| Reliable async messaging | Transactional outbox, `acks=all` + idempotent producer, manual-commit consumer, retries, dead-letter topics, idempotent handlers |
| A repeatable workflow | `create`, `generate service`, `generate kafka-event`, `dev`, `test`, `build`, `update` |
| Optional multi-tenancy | `nestlaravel add tenancy` — tenant-scoped Eloquent, jobs, cache, storage and Kafka events |

## Architecture at a glance

```text
                  Browser / mobile / third parties
                                │  HTTPS
                     ┌──────────▼───────────┐   PUBLIC
                     │  apps/api  (gateway) │   auth · rate limits · CORS · routing
                     └───┬──────────────┬───┘
        HMAC-signed HTTP │              │ HMAC-signed HTTP          INTERNAL (no public ports)
              ┌──────────▼───┐      ┌───▼──────────┐
              │ orders-service│      │ payments-svc │  … your services (Laravel)
              │  own database │      │ own database │
              └──────┬────────┘      └──────┬───────┘
                     │  events (outbox)     │
                     └────────►  Kafka  ◄───┘     SERVICE-TO-SERVICE
```

* **Nx** — monorepo, task graph, caching, affected builds (`project.json` per app).
* **Laravel** — gateway and every microservice.
* **Kafka** — asynchronous integration between services; never a shared database.
* **CLI** (`packages/cli`) — Node.js, zero runtime dependencies. There is **no NestJS runtime** in the framework; see
  [ARCHITECTURE.md](ARCHITECTURE.md#what-about-nestjs) for the reasoning.

Read next: [INSTALLATION.md](INSTALLATION.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [CLI.md](CLI.md) ·
[MICROSERVICES.md](MICROSERVICES.md) · [FRONTEND.md](FRONTEND.md) · [KAFKA.md](KAFKA.md) · [SECURITY.md](SECURITY.md) ·
[DEPLOYMENT.md](DEPLOYMENT.md) · [UPGRADING.md](UPGRADING.md) · [MULTI-TENANCY.md](MULTI-TENANCY.md) ·
[CONTRIBUTING.md](CONTRIBUTING.md) · [CHANGELOG.md](CHANGELOG.md)

## Requirements

| | Minimum | Tested |
|---|---|---|
| Node.js | 20.11 | 20, 22, 24 |
| PHP | 8.3 (`mbstring openssl pdo tokenizer xml ctype json fileinfo bcmath`) | 8.3, 8.4 |
| Composer | 2.6 | 2.x |
| Docker | recommended (Kafka, Postgres, Redis, image builds) | Docker Engine / Desktop 27+ |
| Kafka | 3.6+ (KRaft) | dev image `apache/kafka:4.0.0` |
| Redis | 7+ | 7 |
| PostgreSQL / MySQL / SQLite | 15+ / 8+ / 3 | Postgres 16 |

Laravel `^13.30`, Nx `^23.2`. `php-rdkafka` is needed to reach a real broker from PHP — the Docker images include it;
on a bare host the framework falls back to a log/null driver.

## Repository layout (this repo is the framework source)

```text
apps/api                  gateway (public)                    packages/cli            the `nestlaravel` npm package
apps/*-service            reference microservices             packages/laravel-kafka  Kafka kit (Composer package)
apps/*-portal             optional Next.js portals            packages/laravel-tenancy optional multi-tenancy package
infrastructure/           Dockerfile, scripts, Postgres init  docs/                   topic guides
```

Legacy topic guides (modules, queues, redis, portals, …) remain in [docs/](docs/).

## Development of the framework itself

```bash
npm install                                  # Nx
(cd apps/api && composer install && php vendor/bin/phpunit)
(cd packages/laravel-kafka && composer install && php vendor/bin/phpunit)
(cd packages/laravel-tenancy && composer install && php vendor/bin/phpunit)
(cd packages/cli && npm test)
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and the release process in [DEPLOYMENT.md](DEPLOYMENT.md#publishing-nestlaravel-itself).

## License

MIT — see [LICENSE](LICENSE).
