<?php

namespace App\Modules\Auth\Application\Actions;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

final class LogoutUser
{
    public function execute(User $user, bool $allDevices = false): void
    {
        if ($allDevices) {
            $user->tokens()->delete();

            return;
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
