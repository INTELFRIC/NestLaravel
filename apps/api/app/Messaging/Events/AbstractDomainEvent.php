<?php

namespace App\Messaging\Events;

use App\Messaging\Contracts\DomainEvent;
use Illuminate\Support\Str;

abstract class AbstractDomainEvent implements DomainEvent
{
    private string $eventId;

    private string $occurredAt;

    private ?string $correlationId;

    private ?string $causationId;

    public function __construct(
        ?string $eventId = null,
        ?string $occurredAt = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
        $this->correlationId = $correlationId ?? (app()->bound('correlation_id') ? (string) app('correlation_id') : null);
        $this->causationId = $causationId;
    }

    abstract public function eventType(): string;

    abstract public function aggregateId(): string;

    abstract public function aggregateType(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function payload(): array;

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    public function version(): int
    {
        return 1;
    }

    public function producer(): string
    {
        return (string) config('app.name', 'laravel-enterprise');
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function causationId(): ?string
    {
        return $this->causationId;
    }

    public function toArray(): array
    {
        // Set by the optional nestlaravel/tenancy package; absent in single-tenant apps.
        $tenantId = app()->bound('tenant_id') ? app('tenant_id') : null;

        return array_filter([
            'event_id' => $this->eventId(),
            'event_type' => $this->eventType(),
            'aggregate_id' => $this->aggregateId(),
            'aggregate_type' => $this->aggregateType(),
            'occurred_at' => $this->occurredAt(),
            'version' => $this->version(),
            'producer' => $this->producer(),
            'source' => $this->producer(),
            'correlation_id' => $this->correlationId(),
            'causation_id' => $this->causationId(),
            'tenant_id' => $tenantId,
            'payload' => $this->payload(),
        ], static fn ($value, $key) => $key !== 'tenant_id' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
