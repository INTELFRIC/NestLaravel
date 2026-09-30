<?php

namespace App\Modules\Auth\Application\DTOs;

final readonly class LoginUserData
{
    public function __construct(
        public string $email,
        public string $password,
        public string $deviceName = 'api',
    ) {}
}
