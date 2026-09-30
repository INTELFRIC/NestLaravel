<?php

use App\Modules\Users\Presentation\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('users')->middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('me', [UserProfileController::class, 'me']);
    Route::get('profile', [UserProfileController::class, 'show']);
});
