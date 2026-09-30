# Auth Module Wiring

Sanctum token auth is used for API access. Tokens are created with abilities:

- `access` — standard authenticated API access
- `role:{name}` — one ability per assigned role (e.g. `role:customer`)

No separate refresh-token table is implemented. Clients should re-authenticate when the Sanctum personal access token is revoked or expired. Token abilities can be checked with `$request->user()->tokenCan('access')` or `$request->user()->tokenCan('role:fleet_manager')`.

## 1. Register service providers

Add to `bootstrap/providers.php`:

```php
use App\Providers\AuthModuleServiceProvider;
use App\Modules\Users\Infrastructure\Providers\UsersServiceProvider;
use App\Modules\Notifications\Infrastructure\Providers\NotificationsServiceProvider;

return [
    App\Providers\AppServiceProvider::class,
    AuthModuleServiceProvider::class,
    UsersServiceProvider::class,
    NotificationsServiceProvider::class,
];
```

`AuthModuleServiceProvider` already:

- Loads Auth API routes under `/api/auth/*`
- Registers named rate limiters `auth` and `api`
- Registers Gate helpers (`permission` ability + `platform_admin` bypass)
- Aliases middleware `permission` → `EnsurePermission`

## 2. Middleware / exceptions in `bootstrap/app.php`

`AuthModuleServiceProvider` already:

- aliases `permission`
- overrides guest redirects so API calls do not resolve `route('login')`
- prepends an `AuthenticationException` → HTTP 401 JSON renderer (needed if a catch-all `Throwable` renderer in `bootstrap/app.php` would otherwise turn auth failures into 500)

Preferred long-term fix in `bootstrap/app.php` (instead of the provider prepend):

```php
use Illuminate\Auth\AuthenticationException;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'permission' => \App\Security\Authorization\Middleware\EnsurePermission::class,
    ]);

    $middleware->redirectGuestsTo(fn (Request $request) =>
        $request->is('api/*') || $request->expectsJson() ? null : '/login'
    );
})

->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (AuthenticationException $e, Request $request) {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return ApiResponse::error(message: 'Unauthenticated.', status: 401);
    });

    // Catch-all Throwable renderer must return null for AuthenticationException
    // (or be registered after the handler above and skip auth exceptions).
})
```

Usage example on a protected route:

```php
Route::get('/admin/users', ...)->middleware(['auth:sanctum', 'permission:users.manage']);
```

## 3. Sanctum

Ensure:

- `laravel/sanctum` is installed
- `personal_access_tokens` migration has run
- API requests send `Authorization: Bearer {token}`
- For SPA cookie auth (optional later), configure `SANCTUM_STATEFUL_DOMAINS` and ensure `EnsureFrontendRequestsAreStateful` is applied to the API group

## 4. Database

```bash
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder
```

Seeded roles: `customer`, `fleet_manager`, `workshop`, `supplier`, `platform_admin`.

## 5. Routes exposed

| Method | Path | Auth | Rate limit |
|--------|------|------|------------|
| POST | `/api/auth/register` | public | `throttle:auth` |
| POST | `/api/auth/login` | public | `throttle:auth` |
| POST | `/api/auth/password/forgot` | public | `throttle:auth` |
| POST | `/api/auth/password/reset` | public | `throttle:auth` |
| POST | `/api/auth/logout` | `auth:sanctum` | `throttle:api` |
| GET | `/api/auth/me` | `auth:sanctum` | `throttle:api` |
| GET | `/api/users/me` | `auth:sanctum` | `throttle:api` |
| GET | `/api/users/profile` | `auth:sanctum` | `throttle:api` |

## 6. JSON envelope

Responses use `App\Core\Support\ApiResponse`:

```json
{
  "success": true,
  "data": {},
  "message": "...",
  "meta": { "request_id": "..." }
}
```

## 7. Password reset notifications

`RequestPasswordReset` stubs delivery through `App\Modules\Notifications\Domain\Contracts\Notifier` (`LogNotifier` logs to the app log). Swap the binding in `NotificationsServiceProvider` for mail/SMS later.
