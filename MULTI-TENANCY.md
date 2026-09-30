# Multi-tenancy (optional package)

NestLaravel is single-tenant by default. Multi-tenancy is an **installable package**, `nestlaravel/tenancy`
(`packages/laravel-tenancy`), using the single-database / `tenant_id`-column model — simple to operate, works with the
per-service database rule, and verified by cross-tenant attack tests.

```bash
npx nestlaravel add tenancy                 # all services
npx nestlaravel add tenancy --service orders,api
```

## How the tenant is decided (and why it can't be spoofed)

The **gateway** takes the tenant from the authenticated user (`$user->tenant_id`) — never from a client header — and
puts it in the HMAC-signed service call (`X-Gateway-Tenant`). In the service, `VerifyGatewaySignature` exposes the
verified value as the request attribute `gateway_tenant_id`; the `IdentifyTenant` middleware copies it into
`TenantContext` and clears it after the request. Requests with no tenant get **403**.

## Wiring (once per service)

```php
// migration
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->tenantId();              // indexed string tenant_id
    ...
});

// model
use NestLaravel\Tenancy\Concerns\BelongsToTenant;
class Invoice extends Model { use BelongsToTenant; }

// routes provider
->middleware(['api', VerifyGatewaySignature::class, IdentifyTenant::class])
```

## What is isolated

| Concern | Mechanism | Tested |
|---------|-----------|--------|
| Eloquent reads/updates/deletes | global `TenantScope` (fails closed with `TenantNotResolved` if no tenant) | ✅ read other tenant's row by id, by `where`, mass `update()`/`delete()` |
| Writes | `tenant_id` stamped on create; creating for, or moving a row to, another tenant throws `CrossTenantAccess` | ✅ |
| Queued jobs | tenant captured in the payload and restored in the worker (previous tenant restored after; safe with `sync`) | ✅ |
| Kafka | events carry `tenant_id`; wrap consumers in `TenantAwareHandler` (events without a tenant are dead-lettered) | ✅ |
| Cache | `TenantContext::cacheKey('reports')` → `t:<tenant>:reports` | ✅ |
| File storage | `TenantContext::path('uploads/a.png')` → `tenants/<tenant>/uploads/a.png` (strips `..`) | ✅ |
| Admin/maintenance | `TenantContext::withoutTenancy(fn)` — explicit, greppable | ✅ |

Not covered (by design of the single-DB model): noisy-neighbour isolation and per-tenant encryption keys/backups. For
hard isolation use database-per-tenant (a different package) or separate deployments per tenant.

Run the package tests: `cd packages/laravel-tenancy && composer install && php vendor/bin/phpunit`.
