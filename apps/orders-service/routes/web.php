<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'orders-service',
        'role' => 'internal-backend',
        'gateway' => 'Call apps/api (public). Do not expose this service to browsers.',
    ]);
});

// Readiness probe (orchestrators). Liveness is Laravel's built-in /up.
Route::get('/ready', function () {
    try {
        DB::connection()->getPdo();

        return response()->json(['status' => 'ready']);
    } catch (Throwable) {
        return response()->json(['status' => 'unavailable'], 503);
    }
});
