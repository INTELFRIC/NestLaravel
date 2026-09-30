<?php

namespace NestLaravel\Kafka\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use Throwable;

/**
 * Separate health concepts (Kubernetes vocabulary):
 *
 *  liveness   – "the process is not wedged". Touches NOTHING external. A Kafka/Redis/DB outage must never make an
 *               orchestrator kill a healthy process (restart storms make outages worse).
 *  startup    – "boot finished": DB reachable and the reliability tables exist. Gates the other probes on first start.
 *  readiness  – "route traffic to me": only the dependencies listed in `kafka.health.required` can fail it.
 *  health     – full dependency report for humans/dashboards (never contains hostnames or credentials).
 *
 * Every dependency check is bounded by `kafka.health.timeout_ms` where the driver allows it.
 */
final class HealthChecker
{
    /** @return array{status: string} */
    public function liveness(): array
    {
        return ['status' => 'alive'];
    }

    /** @return array{status: string, checks: array<string, array<string, mixed>>} */
    public function startup(): array
    {
        $checks = ['database' => $this->database()];

        if ($checks['database']['status'] === 'ok' && config('kafka.enabled')) {
            $missing = array_values(array_filter(
                ['outbox_messages', (string) config('kafka.inbox.table', 'inbox_events')],
                static fn (string $t) => ! Schema::hasTable($t),
            ));
            $checks['migrations'] = $missing === []
                ? ['status' => 'ok']
                : ['status' => 'fail', 'message' => 'Missing tables: '.implode(', ', $missing).' (run php artisan migrate)'];
        }

        return ['status' => $this->aggregate($checks, array_keys($checks)), 'checks' => $checks];
    }

    /** @return array{status: string, checks: array<string, array<string, mixed>>} */
    public function readiness(): array
    {
        $required = (array) config('kafka.health.required', ['database']);
        $checks = [];

        foreach ($required as $name) {
            $checks[$name] = $this->check((string) $name);
        }

        return ['status' => $this->aggregate($checks, $required), 'checks' => $checks];
    }

    /** @return array{status: string, checks: array<string, array<string, mixed>>} */
    public function full(): array
    {
        $required = (array) config('kafka.health.required', ['database']);
        $checks = [];

        foreach (['database', 'redis', 'cache', 'kafka'] as $name) {
            $result = $this->check($name);
            $result['required'] = in_array($name, $required, true);
            $checks[$name] = $result;
        }

        try {
            if (Schema::hasTable('outbox_messages')) {
                $checks['outbox'] = ['status' => 'ok', 'required' => false] + $this->outbox();
            }
        } catch (Throwable) {
        }

        $status = $this->aggregate($checks, $required);
        if ($status === 'ok' && array_filter($checks, static fn ($c) => ($c['status'] ?? 'ok') !== 'ok')) {
            $status = 'degraded';
        }

        return ['status' => $status, 'checks' => $checks];
    }

    public function check(string $name): array
    {
        return match ($name) {
            'database' => $this->database(),
            'redis' => $this->redis(),
            'cache' => $this->cache(),
            'kafka' => $this->kafka(),
            default => ['status' => 'fail', 'message' => "Unknown dependency [{$name}] in kafka.health.required"],
        };
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        return $this->timed(function () {
            DB::connection(config('kafka.health.database_connection') ?: null)->select('select 1');

            return ['status' => 'ok'];
        }, 'Database unavailable');
    }

    /** @return array<string, mixed> */
    private function redis(): array
    {
        if (! config('database.redis.default')) {
            return ['status' => 'skipped', 'message' => 'Redis is not configured'];
        }

        return $this->timed(function () {
            $pong = Redis::connection()->ping();
            $ok = $pong === true || (is_string($pong) && stripos($pong, 'PONG') !== false) || (is_object($pong) && stripos((string) $pong, 'PONG') !== false);

            return $ok ? ['status' => 'ok'] : ['status' => 'fail', 'message' => 'Unexpected Redis ping response'];
        }, 'Redis unavailable');
    }

    /** @return array<string, mixed> */
    private function cache(): array
    {
        return $this->timed(function () {
            $key = 'nl:health:'.bin2hex(random_bytes(4));
            Cache::put($key, 1, 10);
            $ok = Cache::get($key) === 1;
            Cache::forget($key);

            return $ok ? ['status' => 'ok'] : ['status' => 'fail', 'message' => 'Cache round-trip failed'];
        }, 'Cache unavailable');
    }

    /** TCP-level broker reachability (does not need ext-rdkafka). */
    private function kafka(): array
    {
        if (! config('kafka.enabled')) {
            return ['status' => 'skipped', 'message' => 'Kafka is disabled (KAFKA_ENABLED=false)'];
        }

        $first = trim(explode(',', (string) config('kafka.brokers', ''))[0] ?? '');
        [$host, $port] = array_pad(explode(':', $first, 2), 2, null);

        if ($host === '' || $port === null || ! ctype_digit((string) $port)) {
            return ['status' => 'fail', 'message' => 'KAFKA_BROKERS is missing or malformed'];
        }

        $timeout = max(0.1, ((int) config('kafka.health.timeout_ms', 1500)) / 1000);
        $start = hrtime(true);
        $socket = @fsockopen($host, (int) $port, $errno, $errstr, $timeout);
        $ms = (int) ((hrtime(true) - $start) / 1e6);

        if ($socket === false) {
            return ['status' => 'fail', 'message' => 'Kafka broker unreachable', 'latency_ms' => $ms];
        }

        fclose($socket);

        return ['status' => 'ok', 'latency_ms' => $ms];
    }

    /** @return array<string, mixed> */
    public function outbox(): array
    {
        $rows = OutboxMessage::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $oldest = OutboxMessage::query()->where('status', OutboxMessage::STATUS_PENDING)->min('created_at');

        return [
            'pending' => (int) ($rows['pending'] ?? 0),
            'processing' => (int) ($rows['processing'] ?? 0),
            'failed' => (int) ($rows['failed'] ?? 0),
            'oldest_pending_age_seconds' => $oldest ? (int) now()->diffInSeconds($oldest, true) : null,
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $fn
     * @return array<string, mixed>
     */
    private function timed(callable $fn, string $failureMessage): array
    {
        $start = hrtime(true);

        try {
            $result = $fn();
        } catch (Throwable $e) {
            // Details go to the logs, never to the (unauthenticated) probe response.
            logger()->warning($failureMessage, ['error' => $e->getMessage()]);
            $result = ['status' => 'fail', 'message' => $failureMessage];
        }

        $result['latency_ms'] = (int) ((hrtime(true) - $start) / 1e6);

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $checks
     * @param  list<string>  $required
     */
    private function aggregate(array $checks, array $required): string
    {
        foreach ($required as $name) {
            if (($checks[$name]['status'] ?? 'fail') === 'fail') {
                return 'fail';
            }
        }

        return 'ok';
    }
}
