<?php

namespace NestLaravel\Kafka\Saga;

/** Mutable, persisted data bag shared by all steps + metadata of the running saga. */
final class SagaContext
{
    /** @param array<string, mixed> $data @param array<string, mixed> $event payload of the event that resumed the saga (if any) */
    public function __construct(
        public readonly string $sagaId,
        public readonly string $name,
        public readonly string $correlationId,
        private array $data,
        public readonly array $event = [],
        public readonly int $attempt = 1,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
