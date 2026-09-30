<?php

namespace NestLaravel\Kafka;

use InvalidArgumentException;
use JsonException;

final class KafkaSerializer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function serialize(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Unable to serialize Kafka payload to JSON.', 0, $e);
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
            throw new InvalidArgumentException('Unable to deserialize Kafka payload from JSON.', 0, $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Kafka payload must decode to an array.');
        }

        return $decoded;
    }
}
