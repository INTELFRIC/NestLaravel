<?php

namespace App\Infrastructure\Gateway;

final class ServiceRegistry
{
    /** @var array<string, ServiceDefinition> */
    private array $services = [];

    public function __construct()
    {
        foreach (config('gateway.services', []) as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $definition = ServiceDefinition::fromConfig((string) $name, $config);
            $this->services[$definition->name] = $definition;
        }
    }

    /**
     * @return array<string, ServiceDefinition>
     */
    public function all(): array
    {
        return $this->services;
    }

    /**
     * @return list<ServiceDefinition>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (ServiceDefinition $service): bool => $service->enabled && $service->baseUrl !== '',
        ));
    }

    public function find(string $name): ?ServiceDefinition
    {
        return $this->services[$name] ?? null;
    }

    public function findByPrefix(string $prefix): ?ServiceDefinition
    {
        $prefix = trim($prefix, '/');

        foreach ($this->enabled() as $service) {
            if ($service->prefix === $prefix) {
                return $service;
            }
        }

        return null;
    }
}
