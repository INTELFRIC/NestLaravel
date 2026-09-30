<?php

namespace App\Modules\Notifications\Infrastructure\Providers;

use App\Modules\Notifications\Domain\Contracts\Notifier;
use App\Modules\Notifications\Infrastructure\Services\LogNotifier;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Notifier::class, LogNotifier::class);
    }

    public function boot(): void
    {
        //
    }
}
