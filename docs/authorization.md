# Authorization

Authorization answers whether an authenticated principal may perform an action on a resource.

## Layers

| Layer | Tool |
|-------|------|
| Route / middleware | `can`, custom middleware, Sanctum abilities |
| Policies | `app/Security/Policies` and/or module policies |
| Application | Explicit checks / domain rules inside Actions when needed |
| Domain | Invariants that are business rules (not HTTP-centric) |

## Recommended approach

1. Authenticate first (`auth:sanctum`).
2. Authorize with Policies for resource actions (`view`, `update`, `delete`).
3. Keep complex multi-entity rules in Domain/Application, throwing a dedicated authorization/domain exception.
4. Map failures to `403` JSON for `api/*` routes.

Example controller shape:

```php
$this->authorize('update', $order);

$order = $this->updateOrder->execute($data);
```

## Module ownership

- Policies that are cross-cutting can live under `app/Security/Policies`.
- Module-specific ability rules can live with the module and be registered from the module provider.
- Do not scatter `if ($user->isAdmin())` checks deep in Infrastructure.

## Rate limiting

Abuse protection lives under `app/Security/RateLimiting` and Laravel’s `RateLimiter`. Prefer Redis-backed limiters in production (see [redis.md](redis.md)).

## Related

- [Authentication](authentication.md)
- [Testing](testing.md)
