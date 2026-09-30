---
name: make-module
description: Scaffolds a Domain/Application/Infrastructure/Presentation business module with Artisan generators. Use when creating a module, vertical slice, make:module, Action, DTO, domain event, or consumer.
---

# Make a business module

Work from `apps/api`.

## Scaffold

```bash
php artisan make:module Orders
```

Register in `apps/api/bootstrap/providers.php`:

```php
use App\Modules\Orders\Infrastructure\Providers\OrdersServiceProvider;

return [
    // existing providers...
    OrdersServiceProvider::class,
];
```

Then:

```bash
php artisan make:dto CreateOrderData --module=Orders
php artisan make:action CreateOrder --module=Orders
php artisan make:domain-event OrderCreated --module=Orders
php artisan make:consumer OrderCreatedConsumer --module=Orders
```

## Implement the slice

```
POST /api/v1/orders
  → CreateOrderRequest
  → CreateOrderData
  → CreateOrder Action
  → OrderRepository (Domain contract)
  → Infrastructure repository + migration
  → OrderCreated
  → EventBus
```

1. Domain entity + repository contract
2. Eloquent model + repository implementation + migration
3. Form Request → DTO → Action → API Resource
4. Bind the repository in the module provider
5. Load routes from `Presentation/Routes/api.php` (already wired by the provider stub)

## Isolation

- Domain: no Laravel facades
- Controllers: Actions only
- Do not share tables or Eloquent across modules
- Copy patterns from `app/Modules/Auth` and `app/Modules/Users`

Inspect existing modules via MCP tools `list-modules` and `describe-module` (server `platform`).
