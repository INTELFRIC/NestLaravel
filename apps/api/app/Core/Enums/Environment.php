<?php

namespace App\Core\Enums;

enum Environment: string
{
    case Local = 'local';
    case Testing = 'testing';
    case Staging = 'staging';
    case Production = 'production';

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    public function isLocal(): bool
    {
        return $this === self::Local;
    }

    public static function current(): self
    {
        $env = (string) config('app.env', 'production');

        return self::tryFrom($env) ?? self::Production;
    }
}
