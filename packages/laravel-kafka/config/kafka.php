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
        // Transient client errors are retried with exponential backoff (base → 30 s). Exit after N in a row
        // (0 = never give up) so the orchestrator restarts the process and alerts on crash loops.
        'max_consecutive_errors' => (int) env('KAFKA_CONSUMER_MAX_CONSECUTIVE_ERRORS', 0),
        'error_backoff_ms' => (int) env('KAFKA_CONSUMER_ERROR_BACKOFF_MS', 500),
        // Close DB/Redis connections when the consumer exits (disable only in tests using in-memory SQLite).
        'close_connections_on_exit' => (bool) env('KAFKA_CONSUMER_CLOSE_CONNECTIONS', true),
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
        // true: refuse to publish events without a registered schema / reject consumed events of an unsupported version.
        'enforce_producer' => (bool) env('KAFKA_SCHEMA_ENFORCE_PRODUCER', false),
        'enforce_consumer' => (bool) env('KAFKA_SCHEMA_ENFORCE_CONSUMER', false),
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

    // Events go to topics[<aggregate_type>] when defined, else to "default".
    // Add more with: nestlaravel generate kafka-topic <name> --service <service>
    'topics' => [
        'default' => env('KAFKA_TOPIC_DEFAULT', 'domain.events'),
        // @nestlaravel:topics
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox Worker
    |--------------------------------------------------------------------------
    */

    'outbox' => [
        'batch_size' => (int) env('KAFKA_OUTBOX_BATCH_SIZE', 100),
        'max_attempts' => (int) env('KAFKA_OUTBOX_MAX_ATTEMPTS', 5),
        // Backoff = retry_delay_seconds * 2^(attempt-1), capped at max_retry_delay_seconds.
        'retry_delay_seconds' => (int) env('KAFKA_OUTBOX_RETRY_DELAY', 30),
        'max_retry_delay_seconds' => (int) env('KAFKA_OUTBOX_MAX_RETRY_DELAY', 900),
        // A row claimed (status=processing) for longer than this is assumed abandoned by a crashed publisher.
        // Must exceed the worst-case produce+flush time of one batch.
        'visibility_timeout_seconds' => (int) env('KAFKA_OUTBOX_VISIBILITY_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbox (transactional idempotency)
    |--------------------------------------------------------------------------
    |
    | When enabled, the consumer pipeline runs each handler inside a DB transaction together with an
    | INSERT into the inbox table (unique consumer + event_id), so a redelivered event can never produce a
    | second business effect. Requires the inbox migration (php artisan migrate) in the SAME database as
    | your business tables. retention_days must exceed the longest possible redelivery window.
    |
    */

    'inbox' => [
        'enabled' => (bool) env('KAFKA_INBOX_ENABLED', false),
        'table' => env('KAFKA_INBOX_TABLE', 'inbox_events'),
        'retention_days' => (int) env('KAFKA_INBOX_RETENTION_DAYS', 14),
        // >1 retries the whole handler transaction on deadlock: only safe when the handler has no external side effects.
        'deadlock_attempts' => (int) env('KAFKA_INBOX_DEADLOCK_ATTEMPTS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event classes (schemas)
    |--------------------------------------------------------------------------
    |
    | Event classes implementing HasEventSchema. Registered at boot for producer- and consumer-side validation.
    | `nestlaravel generate event` appends here.
    |
    */

    'events' => [
        // @nestlaravel:events
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'enabled' => (bool) env('METRICS_ENABLED', true),
        // Cache store shared by all processes of this service (redis in production). null = default store.
        'store' => env('METRICS_CACHE_STORE'),
        // Bearer token required for GET /metrics. Empty = endpoint disabled (fail closed).
        'token' => env('METRICS_TOKEN'),
    ],

    'otel' => [
        'enabled' => (bool) env('OTEL_ENABLED', false),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        'headers' => [],
        'service_name' => env('OTEL_SERVICE_NAME'),
        'timeout_seconds' => (int) env('OTEL_EXPORTER_TIMEOUT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | Dependencies listed in `required` make /readiness fail when they are down. Liveness never depends on them.
    | Kafka is NOT required by default: a service that cannot reach the broker may still serve HTTP.
    |
    */

    'health' => [
        'required' => array_values(array_filter(array_map('trim', explode(',', (string) env('HEALTH_REQUIRED', 'database'))))),
        'timeout_ms' => (int) env('HEALTH_CHECK_TIMEOUT_MS', 1500),
        // Register /liveness /startup /readiness /health (the API gateway keeps its own and sets this to false).
        'routes' => (bool) env('HEALTH_ROUTES', true),
        // Connection used by the database check (null = default connection).
        'database_connection' => env('HEALTH_DB_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Saga
    |--------------------------------------------------------------------------
    */

    'saga' => [
        'compensation_retries' => (int) env('SAGA_COMPENSATION_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Package features
    |--------------------------------------------------------------------------
    |
    | Turn off what the host application already provides itself (the API gateway ships its own copies of the
    | outbox/inbox tables and the kafka:consume / messaging:outbox-publish commands).
    |
    */

    'register' => [
        'migrations' => true,
        'commands' => true,
    ],

    'database' => [
        // Per-session statement timeout applied to pgsql/mysql connections (0 = database default). Generated
        // services set 15000 so a runaway query cannot hold a request or a consumer forever.
        'statement_timeout_ms' => (int) env('DB_STATEMENT_TIMEOUT_MS', 0),
    ],

    'observability' => [
        // Global middleware: request id, correlation id, trace context, RED metrics.
        'http' => (bool) env('OBSERVABILITY_HTTP', true),
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
        // Cache store used for duplicate suppression (null = default cache store).
        'idempotency_store' => env('KAFKA_IDEMPOTENCY_STORE'),
        'idempotency_prefix' => env('KAFKA_IDEMPOTENCY_PREFIX', 'kafka:processed:'),
    ],

];
