# Development

## Requirements

- PHP 8.3+
- Composer 2
- Node.js (frontend assets / Vite)
- Docker (recommended for Postgres, Redis, Kafka)

## First-time setup (host PHP)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

Or Composer’s setup script when available:

```bash
composer setup
```

## Docker setup

See [docker.md](docker.md). Expected Compose services: `app`, `worker`, `postgres`, `redis`, `kafka`, `kafka-ui`.

## Creating a new module

```bash
php artisan make:module Fleet
```

Register `App\Modules\Fleet\Infrastructure\Providers\FleetServiceProvider` in `bootstrap/providers.php`, then:

```bash
php artisan make:dto CreateFleetData --module=Fleet
php artisan make:action CreateFleet --module=Fleet
php artisan make:domain-event FleetCreated --module=Fleet
php artisan make:consumer FleetCreatedConsumer --module=Fleet
```

Add Controllers/Requests/Resources under `Presentation/`, bind repositories in the module provider, and keep Controllers thin.

## DX Artisan commands

| Command | Purpose |
|---------|---------|
| `make:module {Name}` | Scaffold module folders, provider, routes |
| `make:microservice {Name} {--port=}` | Scaffold `apps/{name}-service` and register it on the gateway (proxy off) |
| `make:action {Name} --module=` | Application Action |
| `make:dto {Name} --module=` | Application DTO |
| `make:domain-event {Name} --module=` | Domain event (`AbstractDomainEvent`) |
| `make:consumer {Name} --module=` | Idempotent consumer stub |

Stubs live in `stubs/enterprise/`.

## Running tests

```bash
php artisan test
php artisan test --testsuite=Architecture
```

## Code style

```bash
vendor/bin/pint
```

## Suggested workflow

1. Model the use case in Domain (entity + contract)
2. Add DTO + Action
3. Implement repository in Infrastructure
4. Expose HTTP via Presentation
5. Publish domain events; add consumers only when needed
6. Cover with Unit + Feature tests

## Related

- [Modules](modules.md)
- [Testing](testing.md)
- [Architecture](architecture.md)
