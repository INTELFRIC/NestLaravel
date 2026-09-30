<?php

namespace App\Observability\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthChecker
{
    /**
     * Lightweight process liveness — does not touch dependencies.
     *
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function live(): array
    {
        return [
            'status' => 'ok',
            'checks' => [
                'app' => $this->checkApp(),
            ],
        ];
    }

    /**
     * Readiness — required dependencies for serving traffic.
     *
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function ready(): array
    {
        $checks = [
            'app' => $this->checkApp(),
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        return [
            'status' => $this->aggregateStatus($checks, required: ['app', 'database', 'redis']),
            'checks' => $checks,
        ];
    }

    /**
     * Full health report including optional dependencies (e.g. Kafka).
     *
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function full(): array
    {
        $checks = [
            'app' => $this->checkApp(),
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'kafka' => $this->checkKafka(),
        ];

        return [
            'status' => $this->aggregateStatus($checks, required: ['app', 'database', 'redis']),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkApp(): array
    {
        return [
            'status' => 'ok',
            'message' => 'Application is running.',
        ];
    }

    /**
     * @return array{status: string, message: string, connection?: string}
     */
    private function checkDatabase(): array
    {
        try {
            $connection = (string) config('database.default');
            DB::connection()->getPdo();
            DB::select('select 1');

            return [
                'status' => 'ok',
                'message' => 'Database connection successful.',
                'connection' => $connection,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'message' => $this->safeMessage('Database unavailable.', $e),
            ];
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    private function checkRedis(): array
    {
        try {
            $pong = Redis::connection()->ping();

            $ok = $pong === true
                || $pong === 'PONG'
                || $pong === '+PONG'
                || (is_object($pong) && method_exists($pong, '__toString') && strtoupper((string) $pong) === 'PONG');

            if (! $ok) {
                return [
                    'status' => 'fail',
                    'message' => 'Redis ping returned unexpected response.',
                ];
            }

            return [
                'status' => 'ok',
                'message' => 'Redis connection successful.',
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'message' => $this->safeMessage('Redis unavailable.', $e),
            ];
        }
    }

    /**
     * Kafka is optional until the messaging infrastructure is wired.
     * Missing brokers / client is reported as skipped, not fail.
     *
     * @return array{status: string, message: string, optional: bool}
     */
    private function checkKafka(): array
    {
        $brokers = config('kafka.brokers', env('KAFKA_BROKERS'));

        if (blank($brokers)) {
            return [
                'status' => 'skipped',
                'message' => 'Kafka not configured (KAFKA_BROKERS missing).',
                'optional' => true,
            ];
        }

        try {
            $brokerList = is_array($brokers) ? $brokers : explode(',', (string) $brokers);
            $first = trim((string) ($brokerList[0] ?? ''));

            if ($first === '' || ! str_contains($first, ':')) {
                return [
                    'status' => 'skipped',
                    'message' => 'Kafka broker address is invalid or incomplete.',
                    'optional' => true,
                ];
            }

            [$host, $port] = array_pad(explode(':', $first, 2), 2, null);
            $port = (int) ($port ?: 9092);

            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($host, $port, $errno, $errstr, 1.5);

            if ($socket === false) {
                return [
                    'status' => 'fail',
                    'message' => 'Kafka broker unreachable.',
                    'optional' => true,
                ];
            }

            fclose($socket);

            return [
                'status' => 'ok',
                'message' => 'Kafka broker reachable.',
                'optional' => true,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'message' => $this->safeMessage('Kafka check failed.', $e),
                'optional' => true,
            ];
        }
    }

    /**
     * @param  array<string, array{status: string, message?: string}>  $checks
     * @param  list<string>  $required
     */
    private function aggregateStatus(array $checks, array $required): string
    {
        foreach ($required as $name) {
            if (($checks[$name]['status'] ?? 'fail') !== 'ok') {
                return 'fail';
            }
        }

        return 'ok';
    }

    private function safeMessage(string $fallback, Throwable $e): string
    {
        if (app()->hasDebugModeEnabled()) {
            return $fallback.' '.$e->getMessage();
        }

        return $fallback;
    }
}
