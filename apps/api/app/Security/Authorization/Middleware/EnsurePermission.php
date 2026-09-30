<?php

namespace App\Security\Authorization\Middleware;

use App\Core\Support\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->hasPermission($permission)) {
            return ApiResponse::error(
                message: 'Forbidden.',
                status: 403,
            );
        }

        return $next($request);
    }
}
