<?php

namespace NestLaravel\Kafka\Observability;

/**
 * Per-process bag of ambient logging context (event_id, causation_id, …) set by the consumer pipeline,
 * middleware and sagas. Cleared between units of work so nothing leaks across messages/requests.
 */
final class LogContext
{
    /** @var array<string, scalar|null> */
    private static array $bag = [];

    /** @param array<string, scalar|null> $values */
    public static function set(array $values): void
    {
        self::$bag = array_merge(self::$bag, $values);
    }

    /** @return array<string, scalar|null> */
    public static function all(): array
    {
        return self::$bag;
    }

    public static function forget(string ...$keys): void
    {
        foreach ($keys as $k) {
            unset(self::$bag[$k]);
        }
    }

    public static function clear(): void
    {
        self::$bag = [];
    }

    /**
     * Run a callback with extra context, restoring the previous bag afterwards.
     *
     * @template T
     *
     * @param  array<string, scalar|null>  $values
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function with(array $values, \Closure $callback): mixed
    {
        $previous = self::$bag;
        self::set($values);

        try {
            return $callback();
        } finally {
            self::$bag = $previous;
        }
    }
}
