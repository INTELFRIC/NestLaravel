<?php

namespace NestLaravel\Kafka;

final class KafkaMessage
{
    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $key,
        public readonly string $value,
        public readonly array $headers = [],
        public readonly ?int $partition = null,
        public readonly ?int $offset = null,
        public readonly ?array $payload = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public static function fromPayload(
        string $topic,
        string $key,
        array $payload,
        array $headers = [],
        ?KafkaSerializer $serializer = null,
    ): self {
        $serializer ??= new KafkaSerializer;

        return new self(
            topic: $topic,
            key: $key,
            value: $serializer->serialize($payload),
            headers: $headers,
            payload: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decoded(KafkaSerializer $serializer = new KafkaSerializer): array
    {
        return $this->payload ?? $serializer->deserialize($this->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'topic' => $this->topic,
            'key' => $this->key,
            'value' => $this->value,
            'headers' => $this->headers,
            'partition' => $this->partition,
            'offset' => $this->offset,
            'payload' => $this->payload,
        ];
    }
}
