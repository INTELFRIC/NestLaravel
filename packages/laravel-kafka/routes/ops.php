<?php

use Illuminate\Support\Facades\Route;
use NestLaravel\Kafka\Http\Controllers\OpsController;

/*
| Operational endpoints. INTERNAL interface: expose them only on the cluster network / behind the load balancer's
| private listener. Liveness never depends on external systems; readiness only on `kafka.health.required`.
*/

if (config('kafka.health.routes', true)) {
    Route::get('/liveness', [OpsController::class, 'liveness'])->name('nestlaravel.liveness');
    Route::get('/startup', [OpsController::class, 'startup'])->name('nestlaravel.startup');
    Route::get('/readiness', [OpsController::class, 'readiness'])->name('nestlaravel.readiness');
    Route::get('/health', [OpsController::class, 'health'])->name('nestlaravel.health');
}

// Disabled (404) unless METRICS_TOKEN is set; see OpsController::metrics().
Route::get('/metrics', [OpsController::class, 'metrics'])->name('nestlaravel.metrics');
