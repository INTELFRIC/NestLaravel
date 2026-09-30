# Authentication

Authentication establishes **who** the caller is. Authorization (separate doc) decides **what** they may do.

## Baseline

This starter uses **Laravel Sanctum** for API token / SPA cookie authentication (`laravel/sanctum`).

Typical pieces:

- `app/Models/User` — authenticatable model
- `app/Security/Authentication` — app-specific auth services/guards helpers
- `app/Modules/Auth` — auth-facing application/presentation boundaries
- `config/sanctum.php` + personal access tokens migration

## API authentication pattern

1. Issue a token (or session cookie for first-party SPA) via the Auth module.
2. Protect routes with `auth:sanctum` (or the project’s configured guard).
3. Resolve the user in Controllers / Policies via `$request->user()` — never trust client-supplied user IDs without auth.

```php
Route::middleware('auth:sanctum')->group(function () {
    // module routes
});
```

Module providers may apply middleware when loading routes, or you can group inside `Presentation/Routes/api.php`.

## Rules

1. Controllers stay thin — login/register flows call Auth Application Actions.
2. Passwords are hashed with Laravel’s hasher; never log secrets or tokens.
3. Prefer short-lived tokens / rotation policies in production.
4. CORS and cookie domains must be configured explicitly for SPA auth.

## Local development

```bash
php artisan migrate
# register / login via Auth module endpoints once wired
```

Use Feature tests with `Sanctum::actingAs($user)` (or `$this->actingAs`) rather than real tokens when testing protected APIs.

## Related

- [Authorization](authorization.md)
- [Security concerns in architecture](architecture.md)
- [Testing](testing.md)
