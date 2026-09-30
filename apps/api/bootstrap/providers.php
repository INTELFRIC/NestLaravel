<?php

use App\Modules\Notifications\Infrastructure\Providers\NotificationsServiceProvider;
use App\Modules\Users\Infrastructure\Providers\UsersServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthModuleServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\GatewayServiceProvider;
use App\Providers\InfrastructureServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    InfrastructureServiceProvider::class,
    GatewayServiceProvider::class,
    AuthModuleServiceProvider::class,
    UsersServiceProvider::class,
    NotificationsServiceProvider::class,
];
