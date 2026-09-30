<?php

namespace NestLaravel\Kafka\Serializers;

use NestLaravel\Kafka\Contracts\DomainEvent;
use InvalidArgumentException;
use NestLaravel\Kafka\Exceptions\InvalidEventException;
use JsonException;

final class JsonEventSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(DomainEvent $event): array
    {
        return $event->toArray();
    }

    public function serialize(DomainEvent $event): string
    {
        try {
            return json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Unable to serialize domain event.', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function deserialize(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Unable to deserialize domain event JSON.', 0, $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Domain event payload must decode to an array.');
        }

        $this->validate($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data): void
    {
        foreach (['event_id', 'event_type', 'aggregate_id', 'aggregate_type', 'payload'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidEventException("Domain event missing required field [{$field}].");
            }
        }

        if (! is_array($data['payload'])) {
            throw new InvalidEventException('Domain event payload must be an array.');
        }

        foreach (['event_id', 'event_type'] as $field) {
            if (! is_string($data[$field]) || trim($data[$field]) === '' || strlen($data[$field]) > 191) {
                throw new InvalidEventException("Domain event [{$field}] must be a non-empty string (max 191 chars).");
            }
        }

        $version = $data['event_version'] ?? $data['version'] ?? 1;
        if (! is_int($version) || $version < 1) {
            throw new InvalidEventException('Domain event [event_version] must be a positive integer.');
        }

        if (isset($data['occurred_at']) && (! is_string($data['occurred_at']) || strtotime($data['occurred_at']) === false)) {
            throw new InvalidEventException('Domain event [occurred_at] must be a valid timestamp.');
        }
    }
}
