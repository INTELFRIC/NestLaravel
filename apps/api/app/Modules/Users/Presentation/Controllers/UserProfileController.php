<?php

namespace App\Modules\Users\Presentation\Controllers;

use App\Core\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Users\Application\Queries\GetUserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function me(Request $request, GetUserProfile $query): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            data: $query->execute($user),
            message: 'User profile retrieved.',
        );
    }

    public function show(Request $request, GetUserProfile $query): JsonResponse
    {
        return $this->me($request, $query);
    }
}
