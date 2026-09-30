# Testing

Test from the inside out: Domain → Application → Infrastructure → Feature/API → Architecture.

## Suites

| Suite | Path | Focus |
|-------|------|-------|
| Unit | `tests/Unit` | Actions, domain rules, pure logic with fakes |
| Feature | `tests/Feature` | HTTP API, middleware, wiring |
| Architecture | `tests/Architecture` | Structural invariants (folders, DX commands) |

Run everything:

```bash
php artisan test
```

Run a slice:

```bash
php artisan test --testsuite=Architecture
php artisan test tests/Unit/Application
```

## Guidelines

1. **Unit-test Actions** by faking `EventBus`, repositories, and other contracts — no HTTP, no Kafka.
2. **Feature-test APIs** through Controllers; assert status codes, JSON shape, and side effects you own.
3. Prefer SQLite `:memory:` (already configured) for speed unless a test needs Postgres-specific SQL.
4. Use `QUEUE_CONNECTION=sync` and `CACHE_STORE=array` in tests (phpunit env).
5. For Kafka, test the consumer handler with a fabricated `DomainEvent`; integration tests against a broker are optional/CI-only.

## Example: Action unit test

```php
$action = new CreateOrder($fakeRepo, $fakeEventBus);
$order = $action->execute($dto);
// assert repo received DTO + event published
```

## Architecture tests

`tests/Architecture/ArchitectureTest.php` asserts the enterprise root directories and DX command files exist. Extend it with package-level rules (e.g. Domain must not use Eloquent) as the codebase grows.

## Related

- [Development](development.md)
- [Dependency injection](dependency-injection.md)
