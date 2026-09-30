<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
                            {name : The module name (StudlyCase, e.g. Orders)}
                            {--force : Overwrite the module provider and routes if they already exist}';

    protected $description = 'Scaffold a Domain/Application/Infrastructure/Presentation enterprise module';

    /**
     * @var list<string>
     */
    private array $directories = [
        'Domain/Entities',
        'Domain/ValueObjects',
        'Domain/Events',
        'Domain/Exceptions',
        'Domain/Contracts',
        'Application/Actions',
        'Application/Commands',
        'Application/Queries',
        'Application/DTOs',
        'Application/Services',
        'Infrastructure/Persistence',
        'Infrastructure/Repositories',
        'Infrastructure/Services',
        'Infrastructure/Consumers',
        'Infrastructure/Providers',
        'Presentation/Controllers',
        'Presentation/Requests',
        'Presentation/Resources',
        'Presentation/Routes',
    ];

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $module = Str::studly($this->argument('name'));
        $modulePath = app_path('Modules/'.$module);

        if ($this->files->isDirectory($modulePath) && ! $this->option('force')) {
            $providerPath = $modulePath.'/Infrastructure/Providers/'.$module.'ServiceProvider.php';

            if ($this->files->exists($providerPath)) {
                $this->components->error("Module [{$module}] already exists.");

                return self::FAILURE;
            }
        }

        foreach ($this->directories as $directory) {
            $path = $modulePath.'/'.$directory;
            $this->files->ensureDirectoryExists($path);

            $gitkeep = $path.'/.gitkeep';

            if ($directory !== 'Presentation/Routes'
                && $directory !== 'Infrastructure/Providers'
                && ! $this->files->exists($gitkeep)
                && count($this->files->files($path)) === 0) {
                $this->files->put($gitkeep, '');
            }
        }

        $this->writeFromStub(
            'module-provider.stub',
            $modulePath.'/Infrastructure/Providers/'.$module.'ServiceProvider.php',
            [
                '{{ namespace }}' => "App\\Modules\\{$module}\\Infrastructure\\Providers",
                '{{ class }}' => $module.'ServiceProvider',
            ],
        );

        $this->writeFromStub(
            'module-routes.stub',
            $modulePath.'/Presentation/Routes/api.php',
            [
                '{{ module }}' => $module,
                '{{ routePrefix }}' => Str::kebab(Str::pluralStudly($module)),
            ],
        );

        $this->components->info("Module [{$module}] created at app/Modules/{$module}");
        $this->newLine();
        $this->line('Next steps:');
        $this->line("  1. Register App\\Modules\\{$module}\\Infrastructure\\Providers\\{$module}ServiceProvider in bootstrap/providers.php");
        $this->line("  2. php artisan make:action Create{$module} --module={$module}");
        $this->line("  3. php artisan make:dto Create{$module}Data --module={$module}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function writeFromStub(string $stubName, string $destination, array $replacements): void
    {
        if ($this->files->exists($destination) && ! $this->option('force')) {
            $this->components->warn('Skipped existing file: '.$destination);

            return;
        }

        $stub = $this->files->get(base_path('stubs/enterprise/'.$stubName));
        $contents = str_replace(array_keys($replacements), array_values($replacements), $stub);

        $this->files->ensureDirectoryExists(dirname($destination));
        $this->files->put($destination, $contents);
        $this->components->info('Created: '.$destination);
    }
}
