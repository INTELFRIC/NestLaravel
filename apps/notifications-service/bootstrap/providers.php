<?php

use App\Modules\Notifications\Infrastructure\Providers\NotificationsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    NotificationsServiceProvider::class,
];
