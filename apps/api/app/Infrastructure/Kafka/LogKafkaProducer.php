<?php

namespace App\Infrastructure\Kafka;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Development/test producer that logs messages and appends them to a JSONL file
 * so LogKafkaConsumer can read them later.
 */
final class LogKafkaProducer implements KafkaProducer
{
    public function __construct(
        private readonly KafkaConfig $config = new KafkaConfig,
    ) {}

    public function produce(KafkaMessage $message): void
    {
        Log::info('Kafka produce (log driver)', [
            'topic' => $message->topic,
            'key' => $message->key,
            'headers' => $message->headers,
            'value' => $message->value,
        ]);

        $directory = $this->config->logConsumerPath();
        File::ensureDirectoryExists($directory);

        $line = json_encode([
            'topic' => $message->topic,
            'key' => $message->key,
            'value' => $message->value,
            'headers' => $message->headers,
            'produced_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE);

        File::append($directory.DIRECTORY_SEPARATOR.$this->safeTopicFile($message->topic).'.jsonl', $line.PHP_EOL);
    }

    public function produceMany(array $messages): void
    {
        foreach ($messages as $message) {
            $this->produce($message);
        }
    }

    public function flush(int $timeoutMs = 1000): void
    {
        // no-op for file/log producer
    }

    private function safeTopicFile(string $topic): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $topic) ?: 'topic';
    }
}
