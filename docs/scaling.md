# Scaling

Design for horizontal scale: stateless HTTP nodes + independently scaled workers.

## Scale units

| Unit | Scales by | Notes |
|------|-----------|-------|
| `app` | Replicas behind a load balancer | Session/cache externalized (Redis) |
| `worker` | Replica count | Queues + Kafka consumers |
| `postgres` | Vertical / read replicas | Keep write path simple |
| `redis` | Cluster / managed service | Locks & cache |
| `kafka` | Partitions + consumer groups | Throughput & ordering trade-offs |

```bash
docker compose up --scale worker=5
```

## Stateless app rules

1. No local disk for critical state (use S3/compatible storage).
2. Cache and sessions in Redis (or equivalent).
3. Uploads and generated files on shared object storage.
4. Configuration via environment variables.

## Worker scaling

- Increase workers when queue lag grows.
- Use separate queues for high/low priority work.
- Kafka consumers scale with partition count; one consumer instance per partition within a group for max parallelism.

## Performance practices

- Keep Controllers thin; measure Actions and queries.
- Add indexes for hot read paths.
- Cache reference data via `CacheStore`.
- Prefer async fan-out (Kafka/queues) over synchronous service chains.
- Use pagination and lean API Resources.

## Observability

Correlate requests and async work with `correlation_id` on domain events and structured logs (`app/Observability`).

## Related

- [Docker](docker.md)
- [Queues](queues.md)
- [Kafka](kafka.md)
- [Microservices](microservices.md)
