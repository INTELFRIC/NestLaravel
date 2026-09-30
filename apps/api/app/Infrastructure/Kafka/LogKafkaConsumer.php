<?php

namespace App\Infrastructure\Kafka;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Reads JSONL messages previously written by LogKafkaProducer for local/dev tests.
 */
final class LogKafkaConsumer implements KafkaConsumer
{
    /** @var list<string> */
    private array $topics = [];

    /** @var array<string, int> */
    private array $offsets = [];

    private readonly KafkaSerializer $serializer;

    public function __construct(
        private readonly KafkaConfig $config = new KafkaConfig,
        ?KafkaSerializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new KafkaSerializer;
    }

    public function subscribe(array $topics): void
    {
        $this->topics = array_values($topics);

        foreach ($this->topics as $topic) {
            $this->offsets[$topic] ??= $this->loadOffset($topic);
        }

        Log::debug('LogKafkaConsumer subscribed', ['topics' => $this->topics]);
    }

    public function consume(int $timeoutMs = 1000): ?KafkaMessage
    {
        foreach ($this->topics as $topic) {
            $path = $this->topicPath($topic);

            if (! File::exists($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false || $lines === []) {
                continue;
            }

            $offset = $this->offsets[$topic] ?? 0;

            while ($offset < count($lines)) {
                $raw = trim((string) $lines[$offset]);
                $offset++;
                $this->offsets[$topic] = $offset;

                if ($raw === '') {
                    continue;
                }

                $decoded = json_decode($raw, true);
                if (! is_array($decoded) || ! isset($decoded['value'])) {
                    continue;
                }

                $payload = null;
                try {
                    $payload = $this->serializer->deserialize((string) $decoded['value']);
                } catch (\Throwable) {
                    $payload = null;
                }

                return new KafkaMessage(
                    topic: (string) ($decoded['topic'] ?? $topic),
                    key: (string) ($decoded['key'] ?? ''),
                    value: (string) $decoded['value'],
                    headers: (array) ($decoded['headers'] ?? []),
                    partition: 0,
                    offset: $offset - 1,
                    payload: $payload,
                );
            }
        }

        usleep(min($timeoutMs, 100) * 1000);

        return null;
    }

    public function acknowledge(KafkaMessage $message): void
    {
        $this->persistOffset($message->topic, ($this->offsets[$message->topic] ?? 0));
    }

    public function close(): void
    {
        foreach ($this->topics as $topic) {
            $this->persistOffset($topic, $this->offsets[$topic] ?? 0);
        }

        $this->topics = [];
    }

    private function topicPath(string $topic): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $topic) ?: 'topic';

        return rtrim($this->config->logConsumerPath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$safe.'.jsonl';
    }

    private function offsetPath(string $topic): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $topic) ?: 'topic';

        return rtrim($this->config->logConsumerPath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$safe.'.offset';
    }

    private function loadOffset(string $topic): int
    {
        $path = $this->offsetPath($topic);

        if (! File::exists($path)) {
            return 0;
        }

        return max(0, (int) trim(File::get($path)));
    }

    private function persistOffset(string $topic, int $offset): void
    {
        File::ensureDirectoryExists($this->config->logConsumerPath());
        File::put($this->offsetPath($topic), (string) $offset);
    }
}
