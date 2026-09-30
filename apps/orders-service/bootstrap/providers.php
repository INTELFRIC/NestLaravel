<?php

use App\Modules\Orders\Infrastructure\Providers\OrdersServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    OrdersServiceProvider::class,
];
