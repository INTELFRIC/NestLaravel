# Events

Events are a first-class integration mechanism for this architecture.

## Event kinds

| Kind | Location | Use when |
|------|----------|----------|
| Domain events | `Modules/{M}/Domain/Events` | Something meaningful happened inside a bounded context |
| Integration events | Messaging / Kafka topics | Other modules or services must react asynchronously |
| Application events | Laravel listeners (sparingly) | UI/side concerns that stay in-process |

## Metadata contract

Domain events extend `App\Messaging\Events\AbstractDomainEvent` and implement `App\Messaging\Contracts\DomainEvent`:

```text
event_id
event_type
aggregate_id
aggregate_type
occurred_at
version
producer
correlation_id
causation_id
payload
```

## Creating a domain event

```bash
php artisan make:domain-event OrderCreated --module=Orders
```

Publish via the `EventBus` contract from an Action:

```php
$this->eventBus->publish(new OrderCreated($order));
```

## Publishing strategy

1. **In-process** — useful for modular monolith listeners
2. **Kafka** — for async fan-out and microservice boundaries
3. **Outbox** — for critical “DB commit + event” consistency (see [kafka.md](kafka.md))

Do not publish events from Controllers. Publish from Application Actions after the business operation succeeds (or via outbox in the same DB transaction).

## Naming

Prefer stable dotted types:

```text
orders.order.created
orders.order.paid
payments.payment.failed
```

Keep payloads versionable and free of internal Eloquent models.

## Consumers

```bash
php artisan make:consumer OrderCreatedConsumer --module=Orders
```

Consumers must be **idempotent** (see `IdempotencyStore`).

## Related

- [Kafka](kafka.md)
- [Queues](queues.md)
- [Microservices](microservices.md)
