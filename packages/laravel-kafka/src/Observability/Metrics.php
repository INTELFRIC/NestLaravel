<?php

namespace NestLaravel\Kafka\Observability;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Minimal Prometheus-compatible metrics, stored in the application cache so that every PHP-FPM worker,
 * queue worker and consumer process contributes to the same series (use Redis in production).
 *
 * Metrics are best-effort observability: a failing metrics store must never break request handling or
 * message processing, so write failures are swallowed (and counted in-process for `Metrics::failures()`).
 * Durations are stored as integer microseconds (Cache::increment is integer-only) and exported as seconds.
 */
final class Metrics
{
    /** @var list<float> seconds */
    public const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    private static int $failures = 0;

    public static function enabled(): bool
    {
        return (bool) config('kafka.metrics.enabled', true);
    }

    public static function failures(): int
    {
        return self::$failures;
    }

    /** @param array<string, string|int> $labels */
    public static function inc(string $name, array $labels = [], int $by = 1, string $help = ''): void
    {
        self::write(function () use ($name, $labels, $by, $help): void {
            $key = self::series('counter', $name, $labels, $help);
            self::bump(self::store(), $key, $by);
        });
    }

    /** @param array<string, string|int> $labels */
    public static function gauge(string $name, float|int $value, array $labels = [], string $help = ''): void
    {
        self::write(function () use ($name, $value, $labels, $help): void {
            $key = self::series('gauge', $name, $labels, $help);
            self::store()->forever($key, $value);
        });
    }

    /**
     * Histogram observation in seconds.
     *
     * @param  array<string, string|int>  $labels
     */
    public static function observe(string $name, float $seconds, array $labels = [], string $help = ''): void
    {
        self::write(function () use ($name, $seconds, $labels, $help): void {
            $base = self::series('histogram', $name, $labels, $help);
            $store = self::store();
            self::bump($store, $base.':count', 1);
            self::bump($store, $base.':sum_us', (int) round($seconds * 1_000_000));
            // One write per observation: only the smallest bucket that fits is incremented; render() accumulates.
            // (Cumulative buckets would cost up to 11 extra cache writes per observation — each one a SQL query on the
            // database cache store.)
            foreach (self::BUCKETS as $i => $le) {
                if ($seconds <= $le) {
                    self::bump($store, $base.':b'.$i, 1);

                    break;
                }
            }
        });
    }

    /** Time a callback and record it as a histogram. */
    public static function time(string $name, callable $fn, array $labels = []): mixed
    {
        $start = hrtime(true);

        try {
            return $fn();
        } finally {
            self::observe($name, (hrtime(true) - $start) / 1e9, $labels);
        }
    }

    /** Prometheus text exposition format (version 0.0.4). */
    public static function render(): string
    {
        $store = self::store();
        $index = (array) ($store->get(self::key('index')) ?? []);
        $byName = [];

        foreach ($index as $entry) {
            $byName[$entry['name']][] = $entry;
        }

        ksort($byName);
        $out = [];

        foreach ($byName as $name => $series) {
            $type = $series[0]['type'];
            $help = $series[0]['help'] ?: $name;
            $out[] = "# HELP {$name} ".str_replace("\n", ' ', $help);
            $out[] = "# TYPE {$name} {$type}";

            foreach ($series as $s) {
                $labels = $s['labels'];
                $base = $s['key'];

                if ($type === 'histogram') {
                    $cumulative = 0;
                    foreach (self::BUCKETS as $i => $le) {
                        $cumulative += (int) $store->get($base.':b'.$i, 0);
                        $out[] = $name.'_bucket'.self::fmt($labels + ['le' => (string) $le]).' '.$cumulative;
                    }
                    $count = (int) $store->get($base.':count', 0);
                    $out[] = $name.'_bucket'.self::fmt($labels + ['le' => '+Inf']).' '.$count;
                    $out[] = $name.'_sum'.self::fmt($labels).' '.((int) $store->get($base.':sum_us', 0) / 1_000_000);
                    $out[] = $name.'_count'.self::fmt($labels).' '.$count;
                } else {
                    $out[] = $name.self::fmt($labels).' '.($store->get($base, 0));
                }
            }
        }

        return implode("\n", $out)."\n";
    }

    /** Read one counter/gauge value (for tests and the CLI). */
    public static function value(string $name, array $labels = []): int|float
    {
        return self::store()->get(self::key(md5($name.json_encode(self::sorted($labels)))), 0);
    }

    public static function reset(): void
    {
        self::$failures = 0;
        $store = self::store();
        foreach ((array) ($store->get(self::key('index')) ?? []) as $entry) {
            foreach (['', ':count', ':sum_us'] as $suffix) {
                $store->forget($entry['key'].$suffix);
            }
            foreach (array_keys(self::BUCKETS) as $i) {
                $store->forget($entry['key'].':b'.$i);
            }
        }
        $store->forget(self::key('index'));
    }

    // ------------------------------------------------------------------------------------------------------

    /** @param array<string, string|int> $labels */
    private static function series(string $type, string $name, array $labels, string $help): string
    {
        $labels = self::sorted($labels);
        $key = self::key(md5($name.json_encode($labels)));
        $store = self::store();
        $seenKey = $key.':seen';

        if (! $store->has($seenKey)) {
            $store->put($seenKey, 1, 86400 * 30);
            $lock = $store->lock(self::key('index-lock'), 5);
            try {
                $lock->block(2);
                $index = (array) ($store->get(self::key('index')) ?? []);
                $index[$key] = ['type' => $type, 'name' => $name, 'labels' => $labels, 'help' => $help, 'key' => $key];
                $store->forever(self::key('index'), $index);
            } catch (Throwable) {
                // Losing the index write just delays this series' appearance in /metrics.
            } finally {
                optional($lock)->release();
            }
        }

        return $key;
    }

    /** @param array<string, string|int> $labels */
    private static function sorted(array $labels): array
    {
        $labels = array_map('strval', $labels);
        ksort($labels);

        return $labels;
    }

    /** @param array<string, string> $labels */
    private static function fmt(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $parts = [];
        foreach ($labels as $k => $v) {
            $parts[] = $k.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private static function key(string $suffix): string
    {
        return 'nlmetrics:'.$suffix;
    }

    private static function store(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('kafka.metrics.store') ?: null);
    }

    /**
     * Increment that also works on the first write. Array/Redis create a missing key; the DATABASE cache store (Laravel's
     * default) returns false and stores nothing, which silently lost every counter.
     */
    private static function bump(Repository $store, string $key, int $by): void
    {
        if ($store->increment($key, $by) === false) {
            $store->add($key, 0);
            $store->increment($key, $by);
        }
    }

    /** True while a metric is being written: the write itself must never be measured or counted (see write()). */
    private static bool $writing = false;

    private static function write(callable $fn): void
    {
        // Re-entrancy guard. With the database cache store a metric write IS a SQL query; the query listener would record
        // it as a metric, which writes to the cache, which runs a query… until PHP runs out of stack. Nested writes are
        // dropped instead.
        if (self::$writing || ! self::enabled()) {
            return;
        }

        self::$writing = true;

        try {
            $fn();
        } catch (Throwable) {
            self::$failures++;
        } finally {
            self::$writing = false;
        }
    }
}
