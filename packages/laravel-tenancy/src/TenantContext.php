<?php

namespace NestLaravel\Tenancy;

use Closure;
use NestLaravel\Tenancy\Exceptions\TenantNotResolved;

/**
 * Holds the current tenant for the running request / job / Kafka message.
 *
 * Everything tenant-aware (Eloquent scope, cache keys, storage paths, queued
 * jobs, Kafka events) reads from here, so there is exactly one place where the
 * tenant is decided. Fail-closed: asking for the tenant when none is set throws.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private bool $bypass = false;

    public function set(?string $tenantId): void
    {
        $this->tenantId = ($tenantId === null || $tenantId === '') ? null : $tenantId;

        // Lets tenancy-agnostic packages (Kafka kit) stamp events without depending on us.
        app()->instance('tenant_id', $this->tenantId);

        // Every log line written while a tenant is active carries tenant_id (if the observability kit is installed).
        if (class_exists(\NestLaravel\Kafka\Observability\LogContext::class)) {
            $this->tenantId !== null
                ? \NestLaravel\Kafka\Observability\LogContext::set(['tenant_id' => $this->tenantId])
                : \NestLaravel\Kafka\Observability\LogContext::forget('tenant_id');
        }
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function idOrFail(): string
    {
        return $this->tenantId ?? throw new TenantNotResolved;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function forget(): void
    {
        $this->set(null);
    }

    /**
     * Run a callback as a tenant, restoring the previous tenant afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $tenantId, Closure $callback): mixed
    {
        $previous = $this->tenantId;
        $this->set($tenantId);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }

    /**
     * ADMIN interface: run without tenant scoping (cross-tenant maintenance).
     * Never call this from request handlers that serve tenant users.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutTenancy(Closure $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypass;
    }

    /** Tenant-namespaced cache key, e.g. "t:acme:invoices:list". */
    public function cacheKey(string $key): string
    {
        return 't:'.$this->idOrFail().':'.$key;
    }

    /** Tenant-namespaced storage path, e.g. "tenants/acme/uploads/a.png". */
    public function path(string $relative = ''): string
    {
        $relative = ltrim(str_replace(['..', '\\'], '', $relative), '/');

        return 'tenants/'.$this->idOrFail().($relative !== '' ? '/'.$relative : '');
    }
}
