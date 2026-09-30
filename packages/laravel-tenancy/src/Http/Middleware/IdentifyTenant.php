<?php

namespace NestLaravel\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NestLaravel\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for the request from the gateway-SIGNED identity
 * (request attribute `gateway_tenant_id`, set by VerifyGatewaySignature).
 * Client-supplied tenant headers are never trusted. Requests without a tenant
 * are rejected (403) so no handler can run tenant-less by accident.
 * Register AFTER VerifyGatewaySignature.
 */
final class IdentifyTenant
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->attributes->get(config('tenancy.request_attribute', 'gateway_tenant_id'));

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json(['success' => false, 'message' => 'Tenant could not be resolved.'], 403);
        }

        $this->tenants->set($tenantId);

        try {
            return $next($request);
        } finally {
            // Long-lived workers (Octane/Swoole) must never leak a tenant into the next request.
            $this->tenants->forget();
        }
    }
}
