# Queues

Laravel queues handle deferred work: mail, notifications, outbox publishing, CPU-heavy jobs, and retries.

## Connections

Configure via `QUEUE_CONNECTION`:

| Value | Use |
|-------|-----|
| `sync` | Tests / simple local scripts |
| `database` | Easy local/dev without Redis |
| `redis` | Recommended for production workers |

## Workers

Run workers separately from the HTTP process:

```bash
php artisan queue:work --tries=3 --timeout=90
```

In Docker, the `worker` service runs queue (and optionally Kafka consumer) processes. Scale horizontally:

```bash
docker compose up --scale worker=5
```

## What belongs on a queue

- Sending emails / SMS
- Publishing outbox events to Kafka
- Generating exports/reports
- Any work that must not block the HTTP response

Keep Application Actions synchronous for the core business transaction; dispatch jobs for side effects when eventual consistency is acceptable.

## Reliability

1. Make jobs **idempotent** where retries are possible.
2. Use `--tries` and failed-job handling (`queue:failed`).
3. Prefer small payloads (IDs, not large graphs).
4. Set sensible timeouts; long work should be chunked.

## Scheduling

Use Laravel’s scheduler (`routes/console.php` / `bootstrap/app.php` schedule) for cron-like tasks. The scheduler should only enqueue work; workers execute it.

## Related

- [Redis](redis.md)
- [Kafka](kafka.md)
- [Docker](docker.md)
- [Scaling](scaling.md)
