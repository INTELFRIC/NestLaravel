<?php

namespace App\Modules\Orders\Infrastructure\Providers;

use App\Http\Middleware\VerifyGatewaySignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind module contracts to infrastructure implementations here.
    }

    public function boot(): void
    {
        $this->loadRoutes();
    }

    private function loadRoutes(): void
    {
        Route::prefix('api/v1')
            // INTERNAL interface: only the gateway (holder of the shared secret) may call these routes.
            ->middleware(['api', VerifyGatewaySignature::class])
            ->group(dirname(__DIR__, 2).'/Presentation/Routes/api.php');
    }
}
