<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments Module API Routes
|--------------------------------------------------------------------------
|
| Loaded by PaymentsServiceProvider under the api/v1 prefix.
| Public clients must call the gateway (apps/api), not this service.
|
*/

Route::get('payments/health', function () {
    return response()->json([
        'service' => 'payments-service',
        'status' => 'ok',
    ]);
});
