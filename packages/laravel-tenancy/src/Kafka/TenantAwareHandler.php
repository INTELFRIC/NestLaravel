<?php

namespace NestLaravel\Tenancy\Kafka;

use InvalidArgumentException;
use NestLaravel\Kafka\Consumers\MessageHandler;
use NestLaravel\Tenancy\TenantContext;

/**
 * Wraps a Kafka MessageHandler so it runs inside the tenant named by the event.
 * Events without a tenant_id are treated as poison and dead-lettered.
 *
 *   php artisan kafka:consume orders.events "App\\Handlers\\TenantAwareOrderHandler"
 *   // where the handler class extends TenantAwareHandler or wraps another handler
 */
final class TenantAwareHandler implements MessageHandler
{
    public function __construct(
        private readonly MessageHandler $inner,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(array $event): void
    {
        $tenantId = $event['tenant_id'] ?? null;

        if (! is_string($tenantId) || $tenantId === '') {
            throw new InvalidArgumentException('Event is missing tenant_id; cannot process without a tenant.');
        }

        $this->tenants->run($tenantId, fn () => $this->inner->handle($event));
    }
}
