# Disaster recovery

NestLaravel gives you the *mechanisms* that make recovery safe (durable outbox, idempotent inbox, replayable topics,
persisted sagas). It cannot back up your data or test your restore. This page explains what to protect, in what order to
recover, and how to decide your own objectives. **It contains no RPO/RTO numbers on purpose** – those are business
decisions you must set and then *measure* by rehearsing the procedures below.

## Vocabulary

* **RPO** (recovery point objective) – the most data, measured in time, you accept losing. Determined by backup /
  replication frequency of each store.
* **RTO** (recovery time objective) – the longest outage you accept. Determined by how long detection + restore + verification
  really take. Write down the *measured* time of your last drill, not a target.

## What holds state

| Store | Holds | Loss impact | Protect with |
|-------|-------|-------------|--------------|
| Each service's **database** | business data, `outbox_messages`, `inbox_events`, `saga_instances` | Loss of business truth. Outbox rows not yet published are lost with it; inbox loss allows duplicates to be re-applied | PITR / WAL archiving or snapshots + replication; test restores |
| **Kafka** topics | events in flight / history | Consumers cannot replay; unpublished events still in outbox can be republished | replication factor ≥ 3, `min.insync.replicas` ≥ 2, `acks=all` (default here), MirrorMaker/cluster linking if you need cross-site |
| **Redis** | caches, rate limits, circuit-breaker state, HMAC nonces, metrics counters, queues (if used) | Caches/limits/nonces are **rebuildable**. Redis-backed *queues* are not (use a durable queue driver for critical jobs). | persistence (AOF) only if you queue on it |
| **Secrets** | `APP_KEY`, `INTERNAL_SERVICE_SECRET(_PREVIOUS)`, DB/Kafka/Redis credentials, `METRICS_TOKEN` | Loss of `APP_KEY` makes encrypted columns/sessions unreadable | secret manager with versioning; escrow `APP_KEY` off-platform |
| **Config / images** | Git repository, container images | Rebuildable if the repo and registry exist | Git remote + registry replication |

Design consequence worth knowing: **database and Kafka are updated separately.** After restoring a database from a
backup, the database may be *older* than what Kafka and other services have seen. See "Restoring a database" below.

## Recovery order

1. Secrets and configuration.
2. Databases (each service independently), then run `php artisan migrate --force` (additive migrations only).
3. Redis (empty is fine).
4. Kafka (or reconnect to the surviving cluster).
5. Start **consumers first** (they only react), then the **outbox publishers**, then HTTP replicas.
6. Verify with the checks at the end.

## Scenarios

### Kafka cluster lost or unreachable
* Services keep accepting requests; events wait in each `outbox_messages` (durable, retried with backoff, `failed` after
  `KAFKA_OUTBOX_MAX_ATTEMPTS`). Watch `nestlaravel_outbox_oldest_pending_age_seconds`.
* If the outage exceeds the retry budget, rows end up `failed`: after the broker is back run `nestlaravel outbox:status --failed`
  then `--requeue`.
* Topics recreated empty: events published before the loss are gone from Kafka. Rebuild consumers' state from the source
  services' data (re-emit events from the database) – consumers are idempotent through the inbox, so re-emitting is safe.
  Only the source service can decide what to re-emit; there is no generic tool.

### Consumer group offsets lost
Consumers restart from `KAFKA_AUTO_OFFSET_RESET` (default `earliest`). Everything on the topic is re-delivered and
absorbed by the inbox (`duplicate`). Expect a burst of work, and a lag spike.

### Restoring a database (older than reality)
Data that existed only after the backup point is gone locally, but other services may hold events derived from it.
1. Restore, migrate, keep HTTP traffic **off** and publishers stopped.
2. Reconcile using the source of truth: events Kafka still retains can be replayed to this service (reset its consumer
   group offset to a timestamp; the restored `inbox_events` table tells the inbox what is already applied – events applied
   after the backup point will be applied again, which is exactly what you want).
3. Events this service published after the backup point that it now "forgot" are still on Kafka; its own state
   must be rebuilt from its inputs or from downstream services. Plan this per aggregate; the framework does not
   automate it.
4. Sagas: review `saga_instances` in `waiting`/`compensating`; run `php artisan saga:recover`.

### Redis lost
No recovery needed for correctness: caches refill, breakers close, nonces reset (a replay window equal to the timestamp TTL
is briefly possible only if Redis lost data *and* an attacker captured a valid signed request), metrics restart from zero.
While Redis is down the HMAC layer fails closed with 503 by default – see [FAILURE-SCENARIOS.md](FAILURE-SCENARIOS.md).

### Secret compromised
* `INTERNAL_SERVICE_SECRET`: rotate with zero downtime (procedure in [OPERATIONS.md](OPERATIONS.md#rotating-the-service-to-service-secret-without-downtime)).
* `APP_KEY`: re-encrypt data with the old key before switching; see Laravel's key-rotation guidance (`APP_PREVIOUS_KEYS`).
* Kafka/DB credentials: create new credentials, deploy, revoke old ones.

## Backup checklist (yours to implement)

- [ ] Every service database has automated backups **and** point-in-time recovery if your RPO is smaller than the backup interval.
- [ ] Backups are stored outside the failure domain of the database (other account/region).
- [ ] Kafka topics for business events have replication ≥ 3 and sensible retention (longer than your longest consumer outage
      *and* longer than the inbox retention window `KAFKA_INBOX_RETENTION_DAYS`).
- [ ] Secrets have versioned backups; `APP_KEY` is escrowed.
- [ ] Infrastructure is reproducible from Git (`infrastructure/`, images tagged and retained).
- [ ] Restore of one service database was performed end-to-end and the **elapsed time was recorded**.
- [ ] A game day exercised "Kafka down for N minutes" and "restore DB to a point in the past" in staging.

## Post-recovery verification

```bash
nestlaravel production:check      # configuration sane, migrations present
nestlaravel kafka:health          # broker reachable
nestlaravel outbox:status         # pending draining, failed = 0
nestlaravel dlq:list <topic>      # nothing new parked
nestlaravel tenant:check          # isolation intact after restore
```

Then run one business transaction end to end (create → event → consumer effect) and compare a business invariant
(e.g. every paid order has exactly one payment) between services.
