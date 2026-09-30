<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kafka Feature Flag
    |--------------------------------------------------------------------------
    |
    | When disabled, Null/Log producers and consumers are used so the app
    | runs without ext-rdkafka or a live broker.
    |
    */

    'enabled' => (bool) env('KAFKA_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Outbox Pattern
    |--------------------------------------------------------------------------
    |
    | When true, EventBus writes to outbox_messages and messaging:outbox-publish
    | ships events to Kafka. When false, LaravelEventBus publishes directly.
    |
    */

    'use_outbox' => (bool) env('KAFKA_USE_OUTBOX', true),

    'brokers' => env('KAFKA_BROKERS', 'localhost:9092'),

    'client_id' => env('KAFKA_CLIENT_ID', 'laravel-enterprise'),

    'group_id' => env('KAFKA_GROUP_ID', 'laravel-enterprise-group'),

    'dlq_suffix' => env('KAFKA_DLQ_SUFFIX', '.dlq'),

    /*
    |--------------------------------------------------------------------------
    | Connection security (librdkafka)
    |--------------------------------------------------------------------------
    |
    | protocol: plaintext (local dev only) | ssl | sasl_plaintext | sasl_ssl
    | Production MUST use ssl or sasl_ssl plus broker ACLs (see docs/KAFKA.md).
    | Credentials come from the environment / secret store — never from source.
    |
    */

    'security' => [
        'protocol' => env('KAFKA_SECURITY_PROTOCOL', 'plaintext'),
        'sasl_mechanism' => env('KAFKA_SASL_MECHANISM', 'SCRAM-SHA-512'),
        'sasl_username' => env('KAFKA_SASL_USERNAME'),
        'sasl_password' => env('KAFKA_SASL_PASSWORD'),
        'ssl_ca_location' => env('KAFKA_SSL_CA_LOCATION'),
        'ssl_certificate_location' => env('KAFKA_SSL_CERT_LOCATION'),
        'ssl_key_location' => env('KAFKA_SSL_KEY_LOCATION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery guarantees
    |--------------------------------------------------------------------------
    */

    'producer_options' => [
        'acks' => env('KAFKA_PRODUCER_ACKS', 'all'),
        'enable_idempotence' => (bool) env('KAFKA_PRODUCER_IDEMPOTENCE', true),
        'compression' => env('KAFKA_PRODUCER_COMPRESSION', 'lz4'),
        'delivery_timeout_ms' => (int) env('KAFKA_PRODUCER_DELIVERY_TIMEOUT_MS', 30000),
        'flush_timeout_ms' => (int) env('KAFKA_PRODUCER_FLUSH_TIMEOUT_MS', 10000),
    ],

    'consumer_options' => [
        // Offsets are committed manually after the pipeline succeeds (at-least-once).
        'auto_offset_reset' => env('KAFKA_AUTO_OFFSET_RESET', 'earliest'),
        'session_timeout_ms' => (int) env('KAFKA_SESSION_TIMEOUT_MS', 45000),
        'max_poll_interval_ms' => (int) env('KAFKA_MAX_POLL_INTERVAL_MS', 300000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event schema compatibility
    |--------------------------------------------------------------------------
    |
    | Consumers dead-letter events whose "version" is newer than max_version
    | instead of mis-processing a schema they do not understand. Producers bump
    | version() only for breaking payload changes.
    |
    */

    'schema' => [
        'max_version' => (int) env('KAFKA_EVENT_MAX_VERSION', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Driver Selection
    |--------------------------------------------------------------------------
    |
    | producer/consumer: auto|null|log|rdkafka
    | "auto" picks rdkafka when enabled + extension loaded, else log/null.
    |
    */

    'producer' => env('KAFKA_PRODUCER', 'auto'),

    'consumer' => env('KAFKA_CONSUMER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Topics
    |--------------------------------------------------------------------------
    */

    'topics' => [
        'default' => env('KAFKA_TOPIC_DEFAULT', 'domain.events'),
        'vehicle' => env('KAFKA_TOPIC_VEHICLE', 'vehicle.events'),
        'order' => env('KAFKA_TOPIC_ORDER', 'order.events'),
        'payment' => env('KAFKA_TOPIC_PAYMENT', 'payment.events'),
        'notification' => env('KAFKA_TOPIC_NOTIFICATION', 'notification.events'),
        'fraud' => env('KAFKA_TOPIC_FRAUD', 'fraud.events'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox Worker
    |--------------------------------------------------------------------------
    */

    'outbox' => [
        'batch_size' => (int) env('KAFKA_OUTBOX_BATCH_SIZE', 100),
        'max_attempts' => (int) env('KAFKA_OUTBOX_MAX_ATTEMPTS', 5),
        'retry_delay_seconds' => (int) env('KAFKA_OUTBOX_RETRY_DELAY', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Pipeline
    |--------------------------------------------------------------------------
    */

    'consumer_pipeline' => [
        'max_retries' => (int) env('KAFKA_CONSUMER_MAX_RETRIES', 3),
        'retry_backoff_ms' => (int) env('KAFKA_CONSUMER_RETRY_BACKOFF_MS', 200),
        'idempotency_ttl' => (int) env('KAFKA_IDEMPOTENCY_TTL', 86400),
        'log_path' => env('KAFKA_LOG_CONSUMER_PATH', storage_path('framework/kafka')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Helpers
    |--------------------------------------------------------------------------
    */

    'redis' => [
        'idempotency_prefix' => env('REDIS_IDEMPOTENCY_PREFIX', 'idempotency:'),
        'lock_prefix' => env('REDIS_LOCK_PREFIX', 'lock:'),
        'cache_store' => env('INFRA_CACHE_STORE', env('CACHE_STORE', 'redis')),
    ],

    /*
    |--------------------------------------------------------------------------
    | nestlaravel/kafka package integration
    |--------------------------------------------------------------------------
    |
    | The gateway uses the package for ResilientHttp (timeouts, retries, circuit breaker), request observability and
    | /metrics. It keeps its OWN outbox/inbox tables, commands and health routes, so those package features are off.
    |
    */

    'register' => ['migrations' => false, 'commands' => false],

    'health' => ['routes' => false, 'required' => ['database'], 'timeout_ms' => 1500],

];
