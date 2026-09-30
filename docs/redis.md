# Redis

Redis backs cache, queues (optional), distributed locks, rate limiting, and short-lived idempotency / temporary state.

## Uses in this architecture

| Concern | Contract / mechanism |
|---------|----------------------|
| Cache | `CacheStore` / Laravel cache store |
| Locks | `DistributedLock` |
| Idempotency | `IdempotencyStore` |
| Queues | Laravel `redis` queue connection (optional) |
| Rate limiting | Laravel RateLimiter + Redis |

## Rules

1. Domain code never imports Redis clients.
2. Prefer Core contracts so implementations can swap (array store in tests, Redis in prod).
3. Set TTLs on ephemeral keys (idempotency, locks, rate windows).
4. Do not treat Redis as the system of record for business entities — Postgres/MySQL owns durable state.

## Local configuration

With Docker Compose, the `redis` service is available to `app` and `worker`.

Example env:

```text
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

For pure unit tests, `phpunit.xml` uses `CACHE_STORE=array` and `QUEUE_CONNECTION=sync`.

## Operational notes

- Use key prefixes per environment (`CACHE_PREFIX`, app name).
- Monitor memory and eviction policy in production.
- Distributed locks must always release (try/finally or lock TTL).

## Related

- [Queues](queues.md)
- [Docker](docker.md)
- [Dependency injection](dependency-injection.md)
