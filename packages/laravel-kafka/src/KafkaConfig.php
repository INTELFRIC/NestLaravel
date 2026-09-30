<?php

namespace NestLaravel\Kafka;

final class KafkaConfig
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    public static function fromConfig(?array $config = null): self
    {
        return new self($config ?? (array) config('kafka', []));
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function useOutbox(): bool
    {
        return (bool) ($this->config['use_outbox'] ?? true);
    }

    public function brokers(): string
    {
        return (string) ($this->config['brokers'] ?? 'localhost:9092');
    }

    /** @return list<string> */
    public function brokerList(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $broker): string => trim($broker),
            explode(',', $this->brokers()),
        )));
    }

    public function clientId(): string
    {
        return (string) ($this->config['client_id'] ?? 'laravel-enterprise');
    }

    public function groupId(): string
    {
        return (string) ($this->config['group_id'] ?? 'laravel-enterprise-group');
    }

    public function dlqSuffix(): string
    {
        return (string) ($this->config['dlq_suffix'] ?? '.dlq');
    }

    public function producerDriver(): string
    {
        return (string) ($this->config['producer'] ?? 'auto');
    }

    public function consumerDriver(): string
    {
        return (string) ($this->config['consumer'] ?? 'auto');
    }

    /** @return array<string, string> */
    public function topics(): array
    {
        return (array) ($this->config['topics'] ?? []);
    }

    public function topic(string $name, ?string $default = null): string
    {
        $topics = $this->topics();

        return (string) ($topics[$name] ?? $default ?? $topics['default'] ?? 'domain.events');
    }

    public function dlqTopic(string $topic): string
    {
        $suffix = $this->dlqSuffix();

        if (str_ends_with($topic, $suffix)) {
            return $topic;
        }

        return $topic.$suffix;
    }

    public function outboxBatchSize(): int
    {
        return (int) ($this->config['outbox']['batch_size'] ?? 100);
    }

    public function outboxMaxAttempts(): int
    {
        return (int) ($this->config['outbox']['max_attempts'] ?? 5);
    }

    public function outboxRetryDelaySeconds(): int
    {
        return (int) ($this->config['outbox']['retry_delay_seconds'] ?? 30);
    }

    public function consumerMaxRetries(): int
    {
        return (int) ($this->config['consumer_pipeline']['max_retries'] ?? 3);
    }

    public function idempotencyTtl(): int
    {
        return (int) ($this->config['consumer_pipeline']['idempotency_ttl'] ?? 86400);
    }

    public function logConsumerPath(): string
    {
        return (string) ($this->config['consumer_pipeline']['log_path'] ?? storage_path('framework/kafka'));
    }

    public function outboxVisibilityTimeoutSeconds(): int
    {
        return (int) ($this->config['outbox']['visibility_timeout_seconds'] ?? 300);
    }

    public function outboxMaxRetryDelaySeconds(): int
    {
        return (int) ($this->config['outbox']['max_retry_delay_seconds'] ?? 900);
    }

    public function inboxEnabled(): bool
    {
        return (bool) ($this->config['inbox']['enabled'] ?? false);
    }

    public function inboxTable(): string
    {
        return (string) ($this->config['inbox']['table'] ?? 'inbox_events');
    }

    public function inboxRetentionDays(): int
    {
        return (int) ($this->config['inbox']['retention_days'] ?? 14);
    }

    public function inboxDeadlockAttempts(): int
    {
        return (int) ($this->config['inbox']['deadlock_attempts'] ?? 1);
    }

    public function enforceProducerSchema(): bool
    {
        return (bool) ($this->config['schema']['enforce_producer'] ?? false);
    }

    public function enforceConsumerSchema(): bool
    {
        return (bool) ($this->config['schema']['enforce_consumer'] ?? false);
    }

    /** @return list<string> */
    public function eventClasses(): array
    {
        return array_values((array) ($this->config['events'] ?? []));
    }

    public function retryBackoffMs(): int
    {
        return max(0, (int) ($this->config['consumer_pipeline']['retry_backoff_ms'] ?? 200));
    }

    public function maxEventVersion(): int
    {
        return max(1, (int) ($this->config['schema']['max_version'] ?? 1));
    }

    public function flushTimeoutMs(): int
    {
        return (int) ($this->config['producer_options']['flush_timeout_ms'] ?? 10000);
    }

    /**
     * librdkafka settings shared by producers and consumers: brokers + TLS/SASL.
     *
     * @return array<string, string>
     */
    public function connectionSettings(): array
    {
        $security = (array) ($this->config['security'] ?? []);
        $protocol = strtolower((string) ($security['protocol'] ?? 'plaintext'));

        $settings = [
            'metadata.broker.list' => $this->brokers(),
            'client.id' => $this->clientId(),
            'security.protocol' => $protocol,
        ];

        if (str_starts_with($protocol, 'sasl')) {
            $settings['sasl.mechanisms'] = (string) ($security['sasl_mechanism'] ?? 'SCRAM-SHA-512');
            $settings['sasl.username'] = (string) ($security['sasl_username'] ?? '');
            $settings['sasl.password'] = (string) ($security['sasl_password'] ?? '');
        }

        if (str_contains($protocol, 'ssl')) {
            foreach (['ssl_ca_location', 'ssl_certificate_location', 'ssl_key_location'] as $key) {
                if (! empty($security[$key])) {
                    $settings[str_replace('_', '.', $key)] = (string) $security[$key];
                }
            }
        }

        return $settings;
    }

    /** @return array<string, string> */
    public function producerSettings(): array
    {
        $options = (array) ($this->config['producer_options'] ?? []);

        return $this->connectionSettings() + [
            'acks' => (string) ($options['acks'] ?? 'all'),
            'enable.idempotence' => ($options['enable_idempotence'] ?? true) ? 'true' : 'false',
            'compression.codec' => (string) ($options['compression'] ?? 'lz4'),
            'message.timeout.ms' => (string) ($options['delivery_timeout_ms'] ?? 30000),
            'socket.timeout.ms' => '10000',
        ];
    }

    /** @return array<string, string> */
    public function consumerSettings(): array
    {
        $options = (array) ($this->config['consumer_options'] ?? []);

        return $this->connectionSettings() + [
            'group.id' => $this->groupId(),
            'enable.auto.commit' => 'false',
            'enable.auto.offset.store' => 'false',
            'auto.offset.reset' => (string) ($options['auto_offset_reset'] ?? 'earliest'),
            'session.timeout.ms' => (string) ($options['session_timeout_ms'] ?? 45000),
            'max.poll.interval.ms' => (string) ($options['max_poll_interval_ms'] ?? 300000),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->config;
    }
}
