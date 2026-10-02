# Installation

## 1. Prerequisites

Check your machine any time with:

```bash
npx nestlaravel doctor
```

| Tool | Version | Notes |
|------|---------|-------|
| Node.js | ≥ 20.11 | runs the CLI and Nx |
| npm | ≥ 10 | ships with Node |
| PHP | ≥ 8.3 | extensions: `mbstring openssl pdo tokenizer xml ctype json fileinfo bcmath`; plus `pdo_pgsql`/`pdo_mysql` for those databases |
| Composer | ≥ 2.6 | |
| Docker | recommended | Kafka (KRaft), Postgres, Redis for `nestlaravel dev`; production images |
| php-rdkafka | optional on the host | required to talk to a real broker from PHP outside Docker |

## 2. Create a project

```bash
npx nestlaravel create my-project        # sqlite for the gateway (zero setup)
npx nestlaravel create my-project --db pgsql --migrate
cd my-project
```

`create` performs, in order: requirement checks → workspace scaffold → `.env` files with **fresh random secrets**
(`APP_KEY`, database/Redis passwords) → `composer install` (gateway) → `npm install` (Nx) → optional migrations +
role seeding → `git init` → validation (`nx show projects`, gateway test suite) → next steps.

`--db pgsql|mysql` requires the matching PHP driver (`pdo_pgsql` / `pdo_mysql`); the requirement check names the
`php.ini` to edit when it is missing. With `--db pgsql --migrate` and Docker running, `create` starts the project's own
Postgres container (`docker-compose.infra.yml`) before migrating; if port 5432 is taken it uses the next free port and
writes it to `POSTGRES_PORT` (root `.env`) and `DB_PORT` (`apps/api/.env`). Without Docker, start a Postgres yourself.

Options: `--db sqlite|pgsql|mysql`, `--with-portals`, `--migrate`, `--skip-install`, `--skip-git`, `--skip-validate`,
`--skip-checks`, `-y`.

Nothing is committed: `.env` files are git-ignored and never included in the npm package.

## 3. First service and dev loop

```bash
npx nestlaravel generate service orders
npx nestlaravel dev                # infra (Docker) + all apps
```

* Gateway: <http://127.0.0.1:8000> — service `orders`: port `8001` (internal; reach it via `/api/v1/orders/*`).
* `dev` starts Postgres, Redis and Kafka with Docker Compose when Docker is running; otherwise it warns and
  continues with the log/null Kafka driver. `--no-infra` skips Docker; `--docker` runs the whole stack in containers
  (real Kafka through `ext-rdkafka` inside the images).

## 4. Verify

```bash
npx nestlaravel test        # every app's test suite via Nx
npx nestlaravel lint        # Laravel Pint (style)
npx nestlaravel build       # production Docker images (needs Docker)
```

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `PHP … missing PHP extensions` | enable them in `php.ini` (e.g. `extension=mbstring`) |
| `Docker … daemon not running` | start Docker Desktop / the docker service; `dev --no-infra` works without it |
| `composer install` is slow on Windows | exclude the project folder from Defender real-time scanning |
| Port already used | `generate service payments --port 8055`; gateway port via `php artisan serve --port` |
| Kafka calls do nothing on the host | `KAFKA_ENABLED=false` (default) or no `php-rdkafka`: the log driver is used. Use `dev --docker` |
