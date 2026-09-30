<?php

use App\Modules\Payments\Infrastructure\Providers\PaymentsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PaymentsServiceProvider::class,
];
