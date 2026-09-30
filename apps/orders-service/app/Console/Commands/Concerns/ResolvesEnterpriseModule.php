<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Str;

trait ResolvesEnterpriseModule
{
    protected function resolveModuleName(): string
    {
        $module = $this->option('module');

        if (! is_string($module) || trim($module) === '') {
            $this->fail('The --module option is required (e.g. --module=Orders).');
        }

        $module = Str::studly(trim($module));

        if (! is_dir(app_path('Modules/'.$module))) {
            $this->fail(
                "Module [{$module}] does not exist. Create it first with: php artisan make:module {$module}"
            );
        }

        return $module;
    }

    protected function moduleNamespace(string $module, string $relative): string
    {
        return 'App\\Modules\\'.$module.'\\'.str_replace('/', '\\', trim($relative, '\\/'));
    }

    protected function modulePath(string $module, string $relative): string
    {
        return app_path('Modules/'.$module.'/'.trim(str_replace('\\', '/', $relative), '/'));
    }
}
