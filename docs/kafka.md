> **NestLaravel 1.0 update:** producers use `acks=all` + idempotence, a real rdkafka consumer with manual commits exists, TLS/SASL is configurable, events carry `source`/`tenant_id`. The authoritative guide is [KAFKA.md](../KAFKA.md).

# Kafka

Kafka is used for **asynchronous, event-driven** communication between modules or services. It is not a replacement for every internal method call.

## When to use Kafka

**Use**

- Fan-out after a business fact (OrderCreated → inventory, notifications, analytics)
- Cross-service integration
- Work that can be eventually consistent

**Avoid**

- Simple in-request orchestration inside one module
- Synchronous request/response chains (`A → B → C → D`) for a single API call
- “Because Kafka exists”

## Abstraction

Infrastructure Kafka code lives under `app/Infrastructure/Kafka`. Application and Domain code talk to:

- `App\Core\Contracts\EventBus` for publishing
- Module consumers under `Modules/{M}/Infrastructure/Consumers`

Never call a Kafka client library from Domain code.

## Producer flow

```text
Action
  → EventBus::publish(DomainEvent)
  → (optional Outbox write in same DB transaction)
  → Kafka producer / worker
  → Topic
```

## Consumer flow

```text
Kafka topic
  → Consumer worker
  → Deserialize + validate
  → IdempotencyStore check
  → Module consumer / Application handler
  → Acknowledge
```

Create a consumer stub:

```bash
php artisan make:consumer OrderCreatedConsumer --module=Orders
```

## Outbox pattern

For critical events, persist the business row and an outbox row in one transaction, then a worker publishes to Kafka. This prevents “DB succeeded, Kafka failed, event lost”.

Outbox support belongs under `app/Infrastructure/Outbox`.

## Local development

Docker Compose exposes:

| Service | Role |
|---------|------|
| `kafka` | Broker |
| `kafka-ui` | Topic/browser UI |

Typical env keys (see `.env.example` as they are added by infrastructure work):

```text
KAFKA_BROKERS
KAFKA_CLIENT_ID
KAFKA_GROUP_ID
```

## Related

- [Events](events.md)
- [Docker](docker.md)
- [Scaling](scaling.md)
- [Microservices](microservices.md)
