<?php

namespace NestLaravel\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use NestLaravel\Tenancy\TenantContext;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        // idOrFail(): a query without a tenant throws instead of returning everyone's rows.
        $builder->where($model->qualifyColumn(config('tenancy.column', 'tenant_id')), $context->idOrFail());
    }
}
