<?php

use App\Observability\Health\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'full']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
