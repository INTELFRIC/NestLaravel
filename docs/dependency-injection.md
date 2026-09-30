# Dependency Injection

Use Laravel’s service container for all infrastructure and cross-cutting dependencies. Prefer constructor injection.

## Core contracts

Shared abstractions live under `app/Core/Contracts`:

| Contract | Responsibility |
|----------|----------------|
| `EventBus` | Publish domain/integration events |
| `CacheStore` | Application-level cache access |
| `DistributedLock` | Cross-process locking (Redis) |
| `IdempotencyStore` | Consumer / request idempotency keys |

Bind implementations in service providers (`AppServiceProvider` or dedicated Infrastructure providers).

## Module bindings

Each module provider registers its own contracts:

```php
public function register(): void
{
    $this->app->bind(
        OrderRepository::class,
        EloquentOrderRepository::class,
    );
}
```

Application Actions receive contracts, not concrete Eloquent classes:

```php
final class CreateOrder
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly EventBus $eventBus,
    ) {}
}
```

## Rules

1. **Never** `new` infrastructure clients inside Actions or Domain code.
2. Bind interface → implementation once in a provider.
3. Prefer `final` Actions/DTOs; keep constructors explicit.
4. In tests, bind fakes/mocks of contracts instead of hitting Redis/Kafka.
5. When extracting a microservice, keep the same contracts; swap only the provider bindings / transport.

## Contextual binding (optional)

Use contextual binding when two modules need different implementations of the same shared contract. Prefer module-local contracts when the semantics diverge.

## Related

- [Modules](modules.md)
- [Events](events.md)
- [Testing](testing.md)
