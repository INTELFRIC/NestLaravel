<?php

namespace App\Security\Authorization\Gates;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class AuthorizationGates
{
    public static function register(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if ($user instanceof User && $user->hasRole('platform_admin')) {
                return true;
            }

            return null;
        });

        Gate::define('permission', function ($user, string $permission): bool {
            return $user instanceof User && $user->hasPermission($permission);
        });
    }
}
