> **NestLaravel 1.0 update:** the production stack now uses the shared `infrastructure/docker/laravel.Dockerfile`, KRaft Kafka, required passwords and no published service ports. See [DEPLOYMENT.md](../DEPLOYMENT.md). MinIO is no longer part of the default stack.

# Docker

Production-oriented Docker setup for the Laravel 13 Enterprise Platform.

Stack:

| Service | Role | Host ports |
|---------|------|------------|
| `app` | Laravel HTTP (nginx → php-fpm) | `8000` |
| `worker` | `queue:work redis` (scalable) | — |
| `scheduler` | `schedule:work` | — |
| `postgres` | PostgreSQL 16 | `5432` |
| `redis` | Redis 7 | `6379` |
| `kafka` | Apache Kafka KRaft | `9092` |
| `kafka-ui` | Provectus Kafka UI | `8080` |
| `minio` | S3-compatible object storage | `9000` API, `9001` console |
| `minio-init` | One-shot bucket create (`platform`) | — |
| `mailpit` | Dev SMTP + inbox UI | `1025` SMTP, `8025` UI |

Network: `nest-laravel-net`  
Build context: `apps/api`

> **Port conflicts:** If you already have another stack (Nest/minio/postgres/redis/kafka-ui) running on the same ports, **stop it first** or change host ports in `.env` / compose (`POSTGRES_PORT`, `REDIS_PORT_HOST`, `KAFKA_UI_PORT`, `MINIO_API_PORT`, …).

This compose uses **Kafka KRaft** (no Zookeeper). Your older Confluent+Zookeeper containers are a different stack — do not mix them on the same ports.

---

## 1. First-time setup

```bash
# from repo root
cp apps/api/.env.example apps/api/.env

# optional: generate key on host if PHP is available
cd apps/api && php artisan key:generate && cd ../..
```

Ensure Docker Desktop is running.

---

## 2. Build and start the platform

```bash
# build images + start everything (includes minio-init)
docker compose up -d --build
```

What happens:

1. Builds `nest-laravel-app:latest` from `apps/api/Dockerfile`
2. Starts postgres, redis, kafka, minio, mailpit
3. Runs `minio-init` once → creates bucket `platform`
4. Starts `app`, `worker`, `scheduler`, `kafka-ui`

Check status:

```bash
docker compose ps
docker compose logs -f minio-init
docker compose logs -f app
```

---

## 3. Bootstrap Laravel inside Docker

```bash
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class="Database\\Seeders\\RolePermissionSeeder"
```

Or auto-migrate on boot:

```bash
# PowerShell
$env:RUN_MIGRATIONS=1; docker compose up -d --build
```

---

## 4. URLs

| URL | Service |
|-----|---------|
| http://localhost:8000 | API |
| http://localhost:8000/up | Liveness |
| http://localhost:8000/health | Health report |
| http://localhost:8080 | Kafka UI |
| http://localhost:9001 | MinIO console (`minioadmin` / `minioadmin`) |
| http://localhost:9000 | MinIO S3 API |
| http://localhost:8025 | Mailpit UI |

Portals still run on the host (Node):

```bash
npm install
npm run customer-portal   # :3000
npm run admin-portal      # :3001
```

Point portals at `NEXT_PUBLIC_API_URL=http://localhost:8000/api`.

---

## 5. Useful commands

```bash
# rebuild API image only
docker compose build app

# restart after code/config change (prod image has baked code — rebuild)
docker compose up -d --build app worker scheduler

# scale workers
docker compose up -d --scale worker=5

# shell / artisan
docker compose exec app sh
docker compose exec app php artisan route:list

# stop platform (keep volumes)
docker compose down

# stop + wipe DB/redis/minio data
docker compose down -v
```

---

## 6. Development overlay (live mount)

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build
```

Mounts `apps/api` into the container; MinIO / Mailpit / Kafka stay the same.

---

## 7. MinIO init details

`minio-init` uses `minio/mc:latest` to:

1. Wait until MinIO accepts credentials
2. `mc mb --ignore-existing local/platform`
3. Exit (service `restart: "no"`)

Laravel is wired for path-style S3:

```env
FILESYSTEM_DISK=s3
AWS_ENDPOINT=http://minio:9000          # inside Docker network
AWS_BUCKET=platform
AWS_USE_PATH_STYLE_ENDPOINT=true
```

From the host (portals / local PHP), use `http://127.0.0.1:9000`.

---

## 8. Tests

```bash
docker compose -f docker-compose.yml -f docker-compose.test.yml run --rm --build app
```
