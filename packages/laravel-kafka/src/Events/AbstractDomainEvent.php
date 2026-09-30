<?php

namespace NestLaravel\Kafka\Events;

use Illuminate\Support\Str;
use NestLaravel\Kafka\Contracts\DomainEvent;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\TraceContext;

/**
 * Base class for domain events. Produces the standard NestLaravel envelope:
 *
 *   event_id, event_type, event_version (+ legacy alias `version`), occurred_at, producer (+ alias `source`),
 *   correlation_id, causation_id, tenant_id (when tenancy is active), traceparent (when tracing), payload
 *
 * Context is inherited automatically: an event created while a consumer is handling another event gets that
 * event's correlation_id and uses its event_id as causation_id, so causal chains across services are traceable.
 */
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
        $ambient = LogContext::all();

        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
        $this->correlationId = $correlationId
            ?? (app()->bound('correlation_id') ? (string) app('correlation_id') : null)
            ?? ($ambient['correlation_id'] ?? null);
        $this->causationId = $causationId ?? ($ambient['event_id'] ?? null);
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

    /** Schema version of this event's payload. Bump only for breaking payload changes. */
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
        $trace = TraceContext::current();

        return array_filter([
            'event_id' => $this->eventId(),
            'event_type' => $this->eventType(),
            'event_version' => $this->version(),
            'version' => $this->version(),
            'aggregate_id' => $this->aggregateId(),
            'aggregate_type' => $this->aggregateType(),
            'occurred_at' => $this->occurredAt(),
            'producer' => $this->producer(),
            'source' => $this->producer(),
            'correlation_id' => $this->correlationId(),
            'causation_id' => $this->causationId(),
            'tenant_id' => $tenantId,
            'traceparent' => $trace?->child()->traceparent(),
            'payload' => $this->payload(),
        ], static fn ($value, $key) => $value !== null || ! in_array($key, ['tenant_id', 'traceparent'], true), ARRAY_FILTER_USE_BOTH);
    }
}
