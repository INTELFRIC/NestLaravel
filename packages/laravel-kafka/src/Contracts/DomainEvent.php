<?php

namespace NestLaravel\Kafka\Contracts;

interface DomainEvent
{
    public function eventId(): string;

    public function eventType(): string;

    public function aggregateId(): string;

    public function aggregateType(): string;

    public function occurredAt(): string;

    public function version(): int;

    public function producer(): string;

    public function correlationId(): ?string;

    public function causationId(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
