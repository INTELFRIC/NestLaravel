<?php

namespace App\Modules\Auth\Application\Actions;

use App\Models\User;
use App\Modules\Auth\Application\DTOs\RegisterUserData;
use App\Security\Authorization\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegisterUser
{
    /**
     * @return array{user: User, token: string, token_type: string, abilities: list<string>}
     */
    public function execute(RegisterUserData $data): array
    {
        if (! in_array($data->role, (array) config('auth.self_registration_roles', ['customer']), true)
            || ! Role::query()->where('name', $data->role)->exists()) {
            throw ValidationException::withMessages([
                'role' => ['The selected role is invalid.'],
            ]);
        }

        return DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
            ]);

            $user->assignRole($data->role);
            $user->load('roles');

            $abilities = $user->tokenAbilities();
            $plainTextToken = $user->createToken('api', $abilities)->plainTextToken;

            return [
                'user' => $user,
                'token' => $plainTextToken,
                'token_type' => 'Bearer',
                'abilities' => $abilities,
            ];
        });
    }
}
