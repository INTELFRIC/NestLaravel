<?php

namespace NestLaravel\Kafka\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use NestLaravel\Kafka\Health\HealthChecker;
use Throwable;

/**
 * Inspects the *effective* configuration of one application (what will actually run) and reports findings.
 * It never says "production ready": it reports PASS / WARN / FAIL per concern, and things it cannot verify from inside
 * the application (backups, TLS termination, network policy, ACLs on the broker) are reported as WARN "not verifiable".
 *
 * @phpstan-type Finding array{id: string, status: 'pass'|'warn'|'fail', message: string}
 */
final class ProductionChecker
{
    /** @var list<array{id: string, status: string, message: string}> */
    private array $findings = [];

    /** @return list<array{id: string, status: string, message: string}> */
    public function run(): array
    {
        $this->findings = [];
        $production = config('app.env') === 'production';

        // A section that blows up (dependency down, missing table…) must become a FAIL finding, never a crash:
        // the whole point of this command is to be usable while the system is unhealthy.
        foreach ([
            'environment' => fn () => $this->environment($production),
            'database' => fn () => $this->database($production),
            'state stores' => fn () => $this->stateStores($production),
            'kafka' => fn () => $this->kafka($production),
            'reliability' => fn () => $this->reliability(),
            'security' => fn () => $this->security($production),
            'observability' => fn () => $this->observability(),
            'operations' => fn () => $this->operations(),
        ] as $section => $check) {
            try {
                $check();
            } catch (Throwable $e) {
                $this->fail('check_error_'.str_replace(' ', '_', $section), "The {$section} checks could not run: ".strtok($e->getMessage(), "\n"));
            }
        }

        return $this->findings;
    }

    private function environment(bool $production): void
    {
        $production
            ? $this->pass('environment', 'APP_ENV=production')
            : $this->warn('environment', 'APP_ENV='.config('app.env').' (checks below describe THIS environment, not production)');

        config('app.debug') && $production
            ? $this->fail('debug', 'APP_DEBUG=true in production leaks stack traces and configuration')
            : $this->pass('debug', config('app.debug') ? 'APP_DEBUG=true (non-production)' : 'APP_DEBUG=false');

        strlen((string) config('app.key')) >= 32
            ? $this->pass('app_key', 'APP_KEY is set')
            : $this->fail('app_key', 'APP_KEY is missing');

        $ext = array_filter(['pcntl' => extension_loaded('pcntl'), 'rdkafka' => ! config('kafka.enabled') || extension_loaded('rdkafka')]);
        extension_loaded('pcntl')
            ? $this->pass('graceful_shutdown', 'ext-pcntl loaded: consumers and the outbox publisher stop cleanly on SIGTERM')
            : $this->warn('graceful_shutdown', 'ext-pcntl missing: SIGTERM cannot be handled gracefully by kafka:consume / outbox daemon (Linux images include it)');
        unset($ext);
    }

    private function database(bool $production): void
    {
        try {
            DB::select('select 1');
            $driver = DB::connection()->getDriverName();
            $this->pass('database', "Database reachable ({$driver})");

            if ($production && $driver === 'sqlite') {
                $this->fail('database_engine', 'SQLite in production: no concurrent writers, no per-service isolation, poor for the inbox/outbox');
            }
        } catch (Throwable $e) {
            $this->fail('database', 'Database unreachable: '.$e->getMessage());

            return;
        }

        (int) config('kafka.database.statement_timeout_ms', 0) > 0
            ? $this->pass('db_statement_timeout', 'DB_STATEMENT_TIMEOUT_MS='.config('kafka.database.statement_timeout_ms'))
            : $this->warn('db_statement_timeout', 'No statement timeout: a runaway query can hold a connection indefinitely (set DB_STATEMENT_TIMEOUT_MS)');

        $missing = array_values(array_filter(
            ['outbox_messages', (string) config('kafka.inbox.table', 'inbox_events')],
            static fn (string $t) => ! Schema::hasTable($t),
        ));
        $missing === []
            ? $this->pass('migrations', 'Outbox and inbox tables exist')
            : $this->fail('migrations', 'Missing tables: '.implode(', ', $missing).' (run php artisan migrate)');
    }

    private function stateStores(bool $production): void
    {
        $usesRedis = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);

        if ($usesRedis) {
            try {
                Redis::connection()->ping();
                $this->pass('redis', 'Redis reachable');
            } catch (Throwable $e) {
                $this->fail('redis', 'Redis is configured for cache/queue/session but unreachable: '.$e->getMessage());
            }

            empty(config('database.redis.default.password'))
                ? ($production ? $this->fail('redis_auth', 'Redis has no password') : $this->warn('redis_auth', 'Redis has no password (acceptable on a laptop only)'))
                : $this->pass('redis_auth', 'Redis password set');
        } else {
            $this->warn('redis', 'Redis is not used for cache/queue/session (fine for one instance; several replicas need a shared store)');
        }

        $store = (string) config('cache.default');
        if (in_array($store, ['array', 'file'], true)) {
            $this->warn('shared_cache', "CACHE_STORE={$store} is per-instance: rate limits, circuit breakers and HMAC replay protection are NOT shared between replicas");
        } else {
            $this->pass('shared_cache', "CACHE_STORE={$store} is shared between replicas");
        }

        if (config('queue.default') === 'sync' && $production) {
            $this->warn('queue', 'QUEUE_CONNECTION=sync: jobs run inside the request and are lost on failure');
        }
    }

    private function kafka(bool $production): void
    {
        if (! config('kafka.enabled')) {
            $this->warn('kafka', 'KAFKA_ENABLED=false: events are not shipped to a broker (log/null driver)');

            return;
        }

        $health = (new HealthChecker)->check('kafka');
        $health['status'] === 'ok'
            ? $this->pass('kafka', 'Kafka broker reachable')
            : $this->fail('kafka', 'Kafka broker unreachable (bootstrap server check)');

        $protocol = (string) config('kafka.security.protocol', 'plaintext');
        if ($production && in_array($protocol, ['plaintext', 'sasl_plaintext'], true)) {
            $this->fail('kafka_tls', "KAFKA_SECURITY_PROTOCOL={$protocol}: traffic is not encrypted");
        } else {
            $this->pass('kafka_tls', "KAFKA_SECURITY_PROTOCOL={$protocol}");
        }

        if (str_starts_with($protocol, 'sasl') && (! config('kafka.security.sasl_username') || ! config('kafka.security.sasl_password'))) {
            $this->fail('kafka_sasl', 'SASL enabled but username/password are not configured');
        }

        config('kafka.producer_options.acks', 'all') === 'all' && config('kafka.producer_options.enable_idempotence', true)
            ? $this->pass('kafka_producer', 'acks=all with idempotence')
            : $this->fail('kafka_producer', 'Producer is not using acks=all + idempotence: messages can be lost or duplicated by the broker');

        $this->warn('kafka_acls', 'Broker ACLs / topic replication cannot be verified from inside the application — review docs/KAFKA.md');
    }

    private function reliability(): void
    {
        config('kafka.inbox.enabled')
            ? $this->pass('inbox', 'Transactional inbox enabled (duplicate deliveries cannot repeat business effects)')
            : $this->warn('inbox', 'KAFKA_INBOX_ENABLED=false: idempotency falls back to the weaker cache check');

        config('kafka.schema.enforce_producer')
            ? $this->pass('event_schemas', 'Producer refuses events without a registered schema')
            : $this->warn('event_schemas', 'KAFKA_SCHEMA_ENFORCE_PRODUCER=false: unvalidated event payloads can be published');

        if (Schema::hasTable('outbox_messages')) {
            try {
                $o = (new HealthChecker)->outbox();
                $o['failed'] > 0
                    ? $this->warn('outbox_failed', "{$o['failed']} outbox row(s) in failed state (see: nestlaravel outbox:status)")
                    : $this->pass('outbox_failed', 'No failed outbox rows');
                ($o['oldest_pending_age_seconds'] ?? 0) > 300
                    ? $this->warn('outbox_backlog', 'Oldest pending outbox row is '.$o['oldest_pending_age_seconds'].'s old: is messaging:outbox-publish --daemon running?')
                    : $this->pass('outbox_backlog', 'Outbox backlog is fresh');
            } catch (Throwable) {
            }
        }
    }

    private function security(bool $production): void
    {
        $secret = (string) config('internal.secret', '');
        if (config()->has('internal.secret')) {
            strlen($secret) >= 32
                ? $this->pass('hmac_secret', 'Gateway signing secret is set and long enough')
                : $this->fail('hmac_secret', 'INTERNAL_SERVICE_SECRET is missing or shorter than 32 characters');

            config('internal.replay_protection_required', true)
                ? $this->pass('replay_protection', 'Requests are refused if replay protection is unavailable')
                : $this->warn('replay_protection', 'INTERNAL_REPLAY_PROTECTION_REQUIRED=false: availability is preferred over replay protection');

            (string) config('internal.secret_previous', '') !== ''
                ? $this->warn('hmac_rotation', 'INTERNAL_SERVICE_SECRET_PREVIOUS is set: a secret rotation is in progress — finish it and remove the old secret')
                : $this->pass('hmac_rotation', 'No secret rotation pending');
        }

        (string) config('kafka.metrics.token', '') === ''
            ? $this->warn('metrics_auth', 'METRICS_TOKEN not set: /metrics is disabled (set it to enable scraping)')
            : $this->pass('metrics_auth', '/metrics requires a bearer token');

        if (class_exists(\NestLaravel\Tenancy\TenantContext::class)) {
            config('tenancy.strict_jobs')
                ? $this->pass('tenancy_jobs', 'Tenancy strict_jobs: tenant-less job dispatch is refused')
                : $this->warn('tenancy_jobs', 'Tenancy installed but TENANCY_STRICT_JOBS=false: a job dispatched without a tenant runs without one');
        }
    }

    private function observability(): void
    {
        in_array(config('logging.default'), ['nestlaravel'], true) || str_contains(json_encode(config('logging.channels.'.config('logging.default'))) ?: '', 'JsonLogFormatter')
            ? $this->pass('structured_logging', 'Structured JSON logging with correlation/trace/tenant ids')
            : $this->warn('structured_logging', 'LOG_CHANNEL='.config('logging.default').' is not the structured JSON channel (LOG_CHANNEL=nestlaravel)');

        config('kafka.otel.enabled') && config('kafka.otel.endpoint')
            ? $this->pass('tracing', 'OpenTelemetry span export enabled')
            : $this->warn('tracing', 'Distributed tracing export disabled (trace ids are still propagated and logged)');

        config('kafka.metrics.enabled', true)
            ? $this->pass('metrics', 'Metrics collection enabled')
            : $this->warn('metrics', 'METRICS_ENABLED=false');
    }

    private function operations(): void
    {
        in_array('database', (array) config('kafka.health.required', []), true)
            ? $this->pass('readiness', 'Readiness depends on: '.implode(', ', (array) config('kafka.health.required')))
            : $this->warn('readiness', 'Readiness does not check the database');

        $this->warn('backups', 'Backups and restore procedures cannot be verified by the framework — run and time a restore (docs/DISASTER-RECOVERY.md)');
    }

    private function pass(string $id, string $message): void
    {
        $this->findings[] = ['id' => $id, 'status' => 'pass', 'message' => $message];
    }

    private function warn(string $id, string $message): void
    {
        $this->findings[] = ['id' => $id, 'status' => 'warn', 'message' => $message];
    }

    private function fail(string $id, string $message): void
    {
        $this->findings[] = ['id' => $id, 'status' => 'fail', 'message' => $message];
    }
}
