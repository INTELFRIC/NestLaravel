<?php

namespace App\Modules\Auth\Application\Actions;

use App\Models\User;
use App\Modules\Notifications\Domain\Contracts\Notifier;
use Illuminate\Support\Facades\Password;

final class RequestPasswordReset
{
    public function __construct(
        private readonly Notifier $notifier,
    ) {}

    public function execute(string $email): string
    {
        $status = Password::broker()->sendResetLink(
            ['email' => $email],
            function (User $user, string $token): void {
                $this->notifier->send($user->email, 'password_reset', [
                    'token' => $token,
                    'email' => $user->email,
                    'name' => $user->name,
                ]);
            }
        );

        return $status;
    }
}
