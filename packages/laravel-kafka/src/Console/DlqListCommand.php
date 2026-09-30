<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\KafkaTopic;

/** Peek at the dead-letter topic without consuming it (separate throw-away consumer group, nothing is committed). */
class DlqListCommand extends Command
{
    protected $signature = 'dlq:list {topic? : Source topic (default: the service default topic)} {--limit=20} {--json}';

    protected $description = 'Inspect messages parked on <topic>.dlq (read-only)';

    public function handle(KafkaConfig $config, KafkaTopic $topics): int
    {
        $dlq = $topics->dlq((string) ($this->argument('topic') ?: $topics->resolve('default')));
        $limit = max(1, (int) $this->option('limit'));
        $messages = [];

        if ($config->enabled() && extension_loaded('rdkafka') && $config->consumerDriver() !== 'log') {
            $inspector = new \NestLaravel\Kafka\RdKafkaKafkaConsumer(new KafkaConfig(array_replace(
                $config->toArray(),
                ['group_id' => 'dlq-inspector-'.bin2hex(random_bytes(4)), 'consumer_options' => ['auto_offset_reset' => 'earliest']],
            )));

            try {
                $inspector->subscribe([$dlq]);
                $idle = 0;
                while (count($messages) < $limit && $idle < 5) {
                    $m = $inspector->consume(1000);
                    $m === null ? $idle++ : $messages[] = $m;
                }
            } finally {
                $inspector->close();   // never acknowledge(): the throw-away group's offsets are irrelevant
            }
        } else {
            $file = rtrim($config->logConsumerPath(), '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], '_', $dlq).'.jsonl';
            foreach (is_file($file) ? array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], -$limit) : [] as $line) {
                $row = json_decode($line, true) ?: [];
                $messages[] = new \NestLaravel\Kafka\KafkaMessage($dlq, (string) ($row['key'] ?? ''), (string) ($row['value'] ?? $line), (array) ($row['headers'] ?? []));
            }
        }

        $rows = array_map(static fn ($m) => [
            'partition' => $m->partition, 'offset' => $m->offset, 'key' => $m->key,
            'reason' => $m->headers['dlq_reason'] ?? '', 'original_topic' => $m->headers['original_topic'] ?? '', 'at' => $m->headers['dlq_at'] ?? '',
        ], $messages);

        if ($this->option('json')) {
            $this->line(json_encode(['topic' => $dlq, 'messages' => $rows], JSON_UNESCAPED_SLASHES));
        } elseif ($rows === []) {
            $this->info("No messages on {$dlq}.");
        } else {
            $this->table(['partition', 'offset', 'key', 'reason', 'original topic', 'at'], array_map('array_values', $rows));
        }

        return self::SUCCESS;
    }
}
