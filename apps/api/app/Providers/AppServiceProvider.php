<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Swagger / OpenAPI UI at /docs/api — allow in local & when explicitly enabled.
        Gate::define('viewApiDocs', function (?object $user) {
            return app()->environment('local', 'development', 'testing')
                || (bool) config('scramble.expose_in_production', false);
        });

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT')
                        ->as('sanctum')
                        ->setDescription('Sanctum personal access token from POST /api/auth/login'),
                );
            });
    }
}
