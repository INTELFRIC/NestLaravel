# Database

PostgreSQL is the preferred primary store for this enterprise starter; MySQL remains supported via Laravel’s database layer.

## Ownership

- **Migrations** live in `database/migrations` (Laravel default discovery).
- **Eloquent models used as persistence** belong in module `Infrastructure/Persistence` (or a dedicated Models area if you intentionally keep them thin).
- **Repository contracts** belong in `Domain/Contracts`.
- **Repository implementations** belong in `Infrastructure/Repositories`.

Domain entities are plain PHP objects when you want infrastructure independence; Eloquent models adapt to/from those entities.

## Configuration

```text
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=<generated-password>
```

Local Docker Compose provides the `postgres` service. SQLite in-memory is used in `phpunit.xml` for fast tests.

## Transactions

Wrap multi-step writes in DB transactions inside Application Actions or Infrastructure services:

```php
DB::transaction(function () {
    // persist aggregate
    // write outbox row
});
```

Critical events that must not be lost should use the **outbox** pattern (see [kafka.md](kafka.md)).

## Cross-module data

Avoid shared tables between modules unless there is a deliberate shared kernel. Prefer:

- IDs + events
- Read models owned by the consuming module
- Explicit shared contracts

## Migrations discipline

1. One concern per migration
2. Expand/contract for zero-downtime where needed
3. Never rely on another module’s private schema from Domain code

## Related

- [Architecture](architecture.md)
- [Modules](modules.md)
- [Docker](docker.md)
