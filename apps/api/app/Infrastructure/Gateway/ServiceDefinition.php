<?php

namespace App\Infrastructure\Gateway;

final readonly class ServiceDefinition
{
    public function __construct(
        public string $name,
        public string $prefix,
        public string $baseUrl,
        public float $timeout,
        public bool $enabled,
        public bool $forwardAuth,
        public string $secret = '',
        public bool $public = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            name: $name,
            prefix: trim((string) ($config['prefix'] ?? $name), '/'),
            baseUrl: rtrim((string) ($config['base_url'] ?? ''), '/'),
            timeout: (float) ($config['timeout'] ?? config('gateway.default_timeout', 10)),
            enabled: (bool) ($config['enabled'] ?? false),
            forwardAuth: (bool) ($config['forward_auth'] ?? false),
            secret: (string) ($config['secret'] ?? ''),
            public: (bool) ($config['public'] ?? false),
        );
    }
}
