<?php

namespace App\Modules\Auth\Domain\Contracts;

use App\Models\User;
use App\Modules\Auth\Application\DTOs\LoginUserData;
use App\Modules\Auth\Application\DTOs\RegisterUserData;

interface AuthenticatesUsers
{
    /**
     * @return array{user: User, token: string, token_type: string, abilities: list<string>}
     */
    public function register(RegisterUserData $data): array;

    /**
     * @return array{user: User, token: string, token_type: string, abilities: list<string>}
     */
    public function login(LoginUserData $data): array;

    public function logout(User $user, bool $allDevices = false): void;
}
