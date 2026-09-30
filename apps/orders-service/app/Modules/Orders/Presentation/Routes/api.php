<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders Module API Routes
|--------------------------------------------------------------------------
|
| Loaded by OrdersServiceProvider under the api/v1 prefix.
| Public clients must call the gateway (apps/api), not this service.
|
*/

Route::get('orders/health', function () {
    return response()->json([
        'service' => 'orders-service',
        'status' => 'ok',
    ]);
});
