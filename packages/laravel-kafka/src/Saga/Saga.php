<?php

namespace NestLaravel\Kafka\Saga;

use InvalidArgumentException;

/** Registry + entry point: `Saga::define('name')` in a service provider, `Saga::start('name', [...])` from an Action. */
final class Saga
{
    /** @var array<string, SagaDefinition> */
    private static array $definitions = [];

    public static function define(string $name): SagaDefinition
    {
        return self::$definitions[$name] = new SagaDefinition($name);
    }

    public static function definition(string $name): SagaDefinition
    {
        return self::$definitions[$name] ?? throw new InvalidArgumentException("Saga [{$name}] is not defined.");
    }

    /** @return array<string, SagaDefinition> */
    public static function all(): array
    {
        return self::$definitions;
    }

    public static function flush(): void
    {
        self::$definitions = [];
    }

    /** @param array<string, mixed> $context */
    public static function start(string $name, array $context = [], ?string $correlationId = null): SagaInstance
    {
        return app(SagaOrchestrator::class)->start($name, $context, $correlationId);
    }
}
