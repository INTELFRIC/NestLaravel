<?php

namespace App\Modules\Auth\Application\DTOs;

final readonly class RegisterUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $role = 'customer',
    ) {}
}
