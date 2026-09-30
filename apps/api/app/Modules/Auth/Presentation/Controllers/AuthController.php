<?php

namespace App\Modules\Auth\Presentation\Controllers;

use App\Core\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Application\Actions\LoginUser;
use App\Modules\Auth\Application\Actions\LogoutUser;
use App\Modules\Auth\Application\Actions\RegisterUser;
use App\Modules\Auth\Application\Actions\RequestPasswordReset;
use App\Modules\Auth\Application\Actions\ResetPassword;
use App\Modules\Auth\Application\DTOs\LoginUserData;
use App\Modules\Auth\Application\DTOs\RegisterUserData;
use App\Modules\Auth\Presentation\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Presentation\Requests\LoginRequest;
use App\Modules\Auth\Presentation\Requests\RegisterRequest;
use App\Modules\Auth\Presentation\Requests\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        $result = $action->execute(new RegisterUserData(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            role: $request->string('role', 'customer')->toString(),
        ));

        return ApiResponse::success(
            data: $this->tokenPayload($result),
            message: 'Registration successful.',
            status: 201,
        );
    }

    public function login(LoginRequest $request, LoginUser $action): JsonResponse
    {
        $result = $action->execute(new LoginUserData(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            deviceName: $request->string('device_name', 'api')->toString(),
        ));

        return ApiResponse::success(
            data: $this->tokenPayload($result),
            message: 'Login successful.',
        );
    }

    public function logout(Request $request, LogoutUser $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, $request->boolean('all_devices'));

        return ApiResponse::success(
            data: null,
            message: 'Logged out successfully.',
        );
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('roles');

        return ApiResponse::success(
            data: $this->userPayload($user),
            message: 'Authenticated user.',
        );
    }

    public function forgotPassword(ForgotPasswordRequest $request, RequestPasswordReset $action): JsonResponse
    {
        $action->execute($request->string('email')->toString());

        // Always return the same response to avoid email enumeration.
        return ApiResponse::success(
            data: null,
            message: __(Password::RESET_LINK_SENT),
        );
    }

    public function resetPassword(ResetPasswordRequest $request, ResetPassword $action): JsonResponse
    {
        $status = $action->execute($request->only(
            'email',
            'password',
            'password_confirmation',
            'token',
        ));

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(
                message: __($status),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: null,
            message: __($status),
        );
    }

    /**
     * @param  array{user: User, token: string, token_type: string, abilities: list<string>}  $result
     * @return array<string, mixed>
     */
    private function tokenPayload(array $result): array
    {
        return [
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'abilities' => $result['abilities'],
            'user' => $this->userPayload($result['user']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values()->all(),
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
        ];
    }
}
