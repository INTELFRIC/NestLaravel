# Observability

What a NestLaravel service tells you about itself, how to switch it on, and what it deliberately does **not** do.
Everything below is provided by `nestlaravel/kafka` and is on by default in generated services unless noted.

## 1. Structured logs

Use the `nestlaravel` log channel (`LOG_CHANNEL=nestlaravel`, set in the generated Docker/Compose env). Each line is one
JSON object on stdout:

```json
{"ts":"2026-09-30T13:05:11+00:00","level":"warning","message":"Saga step failed","service":"orders",
 "request_id":"9f1c…","correlation_id":"ord_123","causation_id":"e5d4…","event_id":"e5d4…",
 "tenant_id":"acme","trace_id":"4bf92f3577b34da6a3ce929d0e0e4736","span_id":"00f067aa0ba902b7","context":{…}}
```

* **Correlation** – `request_id`, `correlation_id`, `causation_id`, `event_id`, `tenant_id`, `trace_id`, `span_id` are
  attached automatically from the ambient `LogContext`. The HTTP middleware sets it per request; the Kafka consumer sets
  it per message from the event envelope; events published while handling inherit `correlation_id` and use the handled
  event's id as `causation_id`.
* **Redaction** – `Redactor` masks values whose *key* looks secret (`password`, `secret`, `token`, `authorization`, `api_key`,
  `signature`, `cookie`, card/PAN-like keys…) at any depth, and bearer tokens inside strings. Redaction is key-based: a secret
  logged inside a free-text message is not detectable. Do not log secrets.
* Tenant id is never dropped: it is added from `TenantContext` and travels through jobs and events.

## 2. Metrics (Prometheus)

`GET /metrics` returns Prometheus text format, protected by a bearer token (`METRICS_TOKEN`). **If the token is unset the
endpoint returns 404** (fail closed). Counters/histograms live in the cache store `METRICS_CACHE_STORE` (use Redis in
production so all PHP-FPM workers and daemons aggregate; with `file`/`array` each process only sees itself).

| Area | Metrics |
|------|---------|
| HTTP (RED) | `nestlaravel_http_requests_total`, `nestlaravel_http_request_duration_seconds`, `nestlaravel_http_errors_total` |
| Kafka consumer | `nestlaravel_kafka_consumed_total{result}`, `nestlaravel_kafka_processing_seconds`, `nestlaravel_kafka_retries_total`, `nestlaravel_kafka_dlq_total`, `nestlaravel_kafka_consumer_errors_total`, `nestlaravel_kafka_commit_failures_total` |
| Inbox | `nestlaravel_inbox_total{result=processed|duplicate}` |
| Outbox | `nestlaravel_outbox_published_total`, `_retries_total`, `_failed_total`, `_recovered_total`, `_lost_claims_total`; gauges `nestlaravel_outbox_pending`, `_processing`, `_failed`, `_oldest_pending_age_seconds`; `nestlaravel_events_produced_total` |
| Upstream calls | `nestlaravel_upstream_requests_total`, `nestlaravel_upstream_seconds`, `nestlaravel_upstream_retries_total`, `nestlaravel_circuit_rejected_total`, `nestlaravel_circuit_transitions_total` |
| Saga | `nestlaravel_saga_total{saga,result}` |
| Platform | `nestlaravel_database_up`, `nestlaravel_db_query_duration_seconds`, `nestlaravel_queue_depth`, `nestlaravel_jobs_total`, `nestlaravel_cache_errors_total`, `nestlaravel_process_memory_bytes`, `nestlaravel_process_memory_peak_bytes`, `nestlaravel_host_load` |

Metrics never contain per-request ids, user ids or tenant ids as labels (cardinality); those belong in logs and traces.
Metrics are best-effort: a failing cache never fails a request or a message (it increments `nestlaravel_cache_errors_total` where it can).

### Alerts worth having

| Symptom | Expression (sketch) |
|---------|--------------------|
| Events are not leaving the database | `nestlaravel_outbox_oldest_pending_age_seconds > 60` |
| Publishing is failing permanently | `increase(nestlaravel_outbox_failed_total[10m]) > 0` |
| Consumers are parking messages | `increase(nestlaravel_kafka_dlq_total[10m]) > 0` |
| Offsets not committing | `increase(nestlaravel_kafka_commit_failures_total[10m]) > 0` |
| A dependency is being shed | `increase(nestlaravel_circuit_transitions_total{to="open"}[5m]) > 0` |
| A saga needs a human | `increase(nestlaravel_saga_total{result="failed"}[5m]) > 0` |
| Database unreachable | `nestlaravel_database_up == 0` |

## 3. Tracing (optional)

* **Propagation is always on**: W3C `traceparent` is accepted on HTTP requests, forwarded by the gateway to services,
  written into every event envelope (`traceparent`) and restored by the consumer, so `trace_id` in logs is the same across
  gateway → service → Kafka → consumer.
* **Export is opt-in**: `OTEL_ENABLED=true` + `OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318` sends spans via OTLP/HTTP
  JSON (short timeout, `OTEL_EXPORTER_TIMEOUT`, failures swallowed – tracing must never break a request). No OpenTelemetry PHP
  SDK is required. Sampling is head-based and out of scope; put an OpenTelemetry Collector in front for tail sampling.

## 4. Health endpoints

| Endpoint | Meaning | Depends on |
|----------|---------|------------|
| `GET /liveness` | Process is up and can answer. Restarting fixes nothing else. | nothing |
| `GET /startup` | Boot finished: database reachable, reliability tables migrated. | database |
| `GET /readiness` | Safe to receive traffic. | only the dependencies in `HEALTH_REQUIRED` (default `database`) |
| `GET /health` | Full report per dependency; `degraded` when optional ones are down (HTTP 200), `down` (503) when a required one is. | all |

Kafka is **not** in the default readiness set on purpose: a broker outage must not remove every pod from the load balancer,
because HTTP requests still succeed (events queue in the outbox). Add `kafka` to `HEALTH_REQUIRED` for pure consumer workers.

## 5. Not included

Dashboards, alert routing, log shipping, long-term storage, and a tracing backend. The endpoints and formats above are
standard so any Prometheus / Loki / Elastic / Tempo / Jaeger / Datadog stack can consume them.
