<?php

namespace NestLaravel\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Model;
use NestLaravel\Tenancy\Exceptions\CrossTenantAccess;
use NestLaravel\Tenancy\Scopes\TenantScope;
use NestLaravel\Tenancy\TenantContext;

/**
 * Add to every tenant-owned Eloquent model:
 *
 *   - all queries are scoped to the current tenant (global scope)
 *   - tenant_id is stamped automatically on create
 *   - writing a row for another tenant, or moving a row between tenants, throws
 *
 * The tenant column is deliberately NOT mass-assignable.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $column = config('tenancy.column', 'tenant_id');

            if ($context->isBypassed()) {
                if ($model->getAttribute($column) === null) {
                    throw new CrossTenantAccess('An explicit tenant is required when creating records without tenancy.');
                }

                return;
            }

            $tenantId = $context->idOrFail();
            $given = $model->getAttribute($column);

            if ($given !== null && (string) $given !== $tenantId) {
                throw new CrossTenantAccess;
            }

            $model->setAttribute($column, $tenantId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty(config('tenancy.column', 'tenant_id'))) {
                throw new CrossTenantAccess('A record cannot be moved to another tenant.');
            }
        });
    }
}
