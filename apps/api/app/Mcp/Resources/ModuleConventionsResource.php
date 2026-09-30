<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('How to scaffold and implement a business module with Artisan generators.')]
#[Uri('platform://module-conventions')]
#[MimeType('text/markdown')]
class ModuleConventionsResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text(<<<'MARKDOWN'
# Module conventions

## Scaffold

```bash
cd apps/api
php artisan make:module Orders
```

Register `App\Modules\Orders\Infrastructure\Providers\OrdersServiceProvider` in `bootstrap/providers.php`.

Then:

```bash
php artisan make:dto CreateOrderData --module=Orders
php artisan make:action CreateOrder --module=Orders
php artisan make:domain-event OrderCreated --module=Orders
php artisan make:consumer OrderCreatedConsumer --module=Orders
```

## HTTP slice

```
POST /api/v1/orders
  → CreateOrderRequest (Presentation)
  → CreateOrderData (Application DTO)
  → CreateOrder Action
  → OrderRepository contract (Domain)
  → Eloquent repository (Infrastructure)
  → OrderCreated
  → EventBus (outbox / Kafka when enabled)
```

Controllers stay thin. Actions own use cases. Repository interfaces live in Domain; implementations in Infrastructure.

## Isolation checklist

1. Domain has no Laravel facades
2. Controllers only call Actions
3. Provider binds contracts and loads module routes (`Presentation/Routes/api.php` under `/api/v1`)
4. Do not import another module's Eloquent models
5. Publish events via `EventBus`; do not chain sync HTTP across services

## Agent skills

Use project skills `make-module`, `platform-architecture`, `extract-microservice`, and `make-portal`.
Add a new skill with `php artisan make:agent-skill {name}`.
MARKDOWN);
    }
}
