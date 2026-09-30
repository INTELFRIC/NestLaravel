<?php

namespace App\Modules\Auth\Application\Actions;

use App\Models\User;
use App\Modules\Auth\Application\DTOs\LoginUserData;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginUser
{
    /**
     * @return array{user: User, token: string, token_type: string, abilities: list<string>}
     */
    public function execute(LoginUserData $data): array
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $data->email)->first();

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->load('roles');

        $abilities = $user->tokenAbilities();
        $plainTextToken = $user->createToken($data->deviceName, $abilities)->plainTextToken;

        return [
            'user' => $user,
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
        ];
    }
}
