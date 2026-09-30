# Phase 1 Wiring (manual)

These files were intentionally **not** edited by the Phase 1 generator. Apply the following changes yourself.

## 1. `bootstrap/providers.php`

Add `CoreServiceProvider`:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
];
```

## 2. `bootstrap/app.php` — middleware

Inside `->withMiddleware(...)`, append the global middleware:

```php
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\SecureHeaders;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->append([
        AssignCorrelationId::class,
        SecureHeaders::class,
    ]);
})
```

## 3. Health routes

Prefer a dedicated routes file (e.g. `routes/health.php`) or add to `routes/api.php` / `routes/web.php`:

```php
use App\Observability\Health\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'full']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
```

If using a separate file, register it in `bootstrap/app.php` via `withRouting(then: ...)` or an additional `using` callback.

Example with `then`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function () {
        require __DIR__.'/../routes/health.php';
    },
)
```

## 4. Container aliases (already bound at request time)

`AssignCorrelationId` binds:

| Binding key       | Description                          |
|-------------------|--------------------------------------|
| `correlation_id`  | Trace ID for the request / async flow |
| `request_id`      | HTTP request ID (may equal correlation) |
| `CorrelationId`   | Singleton service instance           |

`ApiResponse` and `AbstractDomainEvent` read `correlation_id` / `request_id` when bound.

## 5. Optional exception rendering

In `bootstrap/app.php` `->withExceptions(...)`, map `App\Core\Exceptions\DomainException` to `ApiResponse::error(...)` using `$e->statusCode()`, `$e->getMessage()`, and `$e->context()` — keep stack traces out of production responses.
