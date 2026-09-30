<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications Module API Routes
|--------------------------------------------------------------------------
|
| Loaded by NotificationsServiceProvider under the api/v1 prefix.
| Public clients must call the gateway (apps/api), not this service.
|
*/

Route::get('notifications/health', function () {
    return response()->json([
        'service' => 'notifications-service',
        'status' => 'ok',
    ]);
});
