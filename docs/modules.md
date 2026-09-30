# Modules

Every business capability lives in `app/Modules/{Name}`.

## Creating a module

```bash
php artisan make:module Orders
```

This scaffolds:

```text
app/Modules/Orders/
├── Domain/{Entities,ValueObjects,Events,Exceptions,Contracts}/
├── Application/{Actions,Commands,Queries,DTOs,Services}/
├── Infrastructure/{Persistence,Repositories,Services,Consumers,Providers}/
└── Presentation/{Controllers,Requests,Resources,Routes}/
```

It also creates:

- `Infrastructure/Providers/OrdersServiceProvider.php`
- `Presentation/Routes/api.php` (loaded under `api/v1`)

Register the provider in `bootstrap/providers.php`:

```php
use App\Modules\Orders\Infrastructure\Providers\OrdersServiceProvider;

return [
    App\Providers\AppServiceProvider::class,
    OrdersServiceProvider::class,
];
```

## Generating module artifacts

```bash
php artisan make:action CreateOrder --module=Orders
php artisan make:dto CreateOrderData --module=Orders
php artisan make:domain-event OrderCreated --module=Orders
php artisan make:consumer OrderCreatedConsumer --module=Orders
```

## Recommended request flow

```text
POST /api/v1/orders
  → CreateOrderRequest (validation)
  → CreateOrderData (DTO)
  → CreateOrder Action
  → OrderRepository (contract)
  → Infrastructure repository
  → OrderCreated domain event
  → EventBus (local +/or Kafka)
```

## Module communication

**Allowed**

- Inject another module’s public **contract** (bound in that module’s provider)
- Publish/subscribe **domain or integration events**
- Call a thin application service exposed for cross-module use

**Not allowed**

- Import another module’s Eloquent models
- Reach into `Infrastructure/Repositories` of another module
- Share database tables casually across module boundaries without an explicit ownership decision

## Isolation checklist

1. Domain has no Laravel facades
2. Controllers only call Actions
3. Repository interfaces live in Domain; implementations in Infrastructure
4. Provider binds contracts and loads routes
5. Routes stay inside the module (not in `routes/api.php`)

## Modules shipped in this starter

| Module | Purpose |
|--------|---------|
| Auth | Authentication boundaries (Sanctum) |
| Users | User identity / profile shell |
| Notifications | Outbound notification capability (`LogNotifier`) |

Add your domain modules with `php artisan make:module {Name}`. See [architecture.md](architecture.md) and [development.md](development.md).
