<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class MakeMicroserviceCommand extends Command
{
    protected $signature = 'make:microservice
                            {name : StudlyCase domain name (e.g. Inventory)}
                            {--port= : Host port for php artisan serve (default: next free from 8001)}
                            {--force : Overwrite apps/{name}-service if it already exists}';

    protected $description = 'Scaffold a Laravel microservice under apps/{name}-service and register it on the gateway';

    /**
     * @var list<string>
     */
    private array $reserved = ['Auth', 'Api', 'Gateway', 'Platform', 'Admin', 'Customer'];

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $module = Str::studly((string) $this->argument('name'));
        $module = (string) preg_replace('/Service$/i', '', $module);

        if ($module === '') {
            $this->components->error('Provide a domain name, e.g. Inventory.');

            return self::FAILURE;
        }

        if (in_array($module, $this->reserved, true)) {
            $this->components->error("[{$module}] stays on the gateway. Pick a business domain (Orders, Inventory, …).");

            return self::FAILURE;
        }

        $kebab = Str::kebab($module);
        $env = Str::upper(Str::snake($module));
        $dirName = $kebab.'-service';
        $repoRoot = dirname(base_path(), 2);
        $destination = $repoRoot.DIRECTORY_SEPARATOR.'apps'.DIRECTORY_SEPARATOR.$dirName;
        // Projects created by `nestlaravel create` keep the template under .nestlaravel/service-template.
        $source = $this->files->isDirectory($repoRoot.DIRECTORY_SEPARATOR.'.nestlaravel'.DIRECTORY_SEPARATOR.'service-template')
            ? $repoRoot.DIRECTORY_SEPARATOR.'.nestlaravel'.DIRECTORY_SEPARATOR.'service-template'
            : $repoRoot.DIRECTORY_SEPARATOR.'apps'.DIRECTORY_SEPARATOR.'orders-service';
        $port = (int) ($this->option('port') ?: $this->nextPort($repoRoot));

        if ($port < 1 || $port > 65535) {
            $this->components->error('Port must be between 1 and 65535.');

            return self::FAILURE;
        }

        if (! $this->files->isDirectory($source)) {
            $this->components->error('Missing service template (.nestlaravel/service-template or apps/orders-service).');

            return self::FAILURE;
        }

        if ($this->files->isDirectory($destination)) {
            if (! $this->option('force')) {
                $this->components->error("apps/{$dirName} already exists. Pass --force to replace it.");

                return self::FAILURE;
            }

            $this->files->deleteDirectory($destination);
        }

        $this->copySkeleton($source, $destination);
        $this->renameModule($destination, $module);
        $this->rewriteCopiedFiles($destination, $module, $kebab, $env, $dirName, $port);
        $this->writeProjectJson($destination, $dirName, $kebab, $port);
        $this->writeReadme($destination, $module, $dirName, $env, $port);
        $this->registerGateway($module, $kebab, $env, $port);
        $this->appendEnvExample($env, $dirName, $port);
        $this->appendRootNpmScript($repoRoot, $dirName, $port);

        $this->components->info("Microservice [{$dirName}] created at apps/{$dirName}");
        $this->newLine();
        $this->line('Next commands:');
        $this->newLine();
        $this->line("  cd apps/{$dirName}");
        $this->line('  composer install');
        $this->line('  cp .env.example .env');
        $this->line('  php artisan key:generate');
        $this->line('  php artisan make:action Create'.$module.' --module='.$module);
        $this->line('  php artisan make:dto Create'.$module.'Data --module='.$module);
        $this->newLine();
        $this->line('Run this service only:');
        $this->line("  npx nx serve {$dirName}");
        $this->newLine();
        $this->line('Enable the gateway proxy when you are ready (apps/api/.env):');
        $this->line("  GATEWAY_{$env}_ENABLED=true");
        $this->line("  {$env}_SERVICE_URL=http://127.0.0.1:{$port}");
        $this->newLine();
        $this->line('Portals still call apps/api only. Auth stays on the gateway.');

        return self::SUCCESS;
    }

    private function nextPort(string $repoRoot): int
    {
        $max = 8000;
        $apps = $repoRoot.DIRECTORY_SEPARATOR.'apps';

        if (! $this->files->isDirectory($apps)) {
            return 8001;
        }

        foreach ($this->files->directories($apps) as $dir) {
            $project = $dir.DIRECTORY_SEPARATOR.'project.json';

            if (! $this->files->exists($project)) {
                continue;
            }

            if (preg_match('/--port=(\d+)/', $this->files->get($project), $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max + 1;
    }

    private function copySkeleton(string $from, string $to): void
    {
        $from = rtrim($from, '/\\');
        $skip = ['vendor', 'node_modules', '.env', '.phpunit.cache', '.git'];

        $directory = new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            fn (SplFileInfo $current): bool => ! in_array($current->getFilename(), $skip, true),
        );

        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $item) {
            /** @var SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from));
            $target = $to.$relative;

            if ($item->isDir()) {
                $this->files->ensureDirectoryExists($target);

                continue;
            }

            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->copy($item->getPathname(), $target);
        }
    }

    private function renameModule(string $destination, string $module): void
    {
        $modules = $destination.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules';
        $from = $modules.DIRECTORY_SEPARATOR.'Orders';
        $to = $modules.DIRECTORY_SEPARATOR.$module;

        if ($this->files->isDirectory($from) && $from !== $to) {
            if ($this->files->isDirectory($to)) {
                $this->files->deleteDirectory($to);
            }

            // A plain rename can fail on Windows while antivirus/indexers hold the freshly copied files;
            // fall back to copy + delete so scaffolding never silently leaves the template module name.
            if (! $this->files->moveDirectory($from, $to)) {
                $this->files->copyDirectory($from, $to);
                $this->files->deleteDirectory($from);
            }
        }

        $oldProvider = $to.DIRECTORY_SEPARATOR.'Infrastructure'.DIRECTORY_SEPARATOR.'Providers'.DIRECTORY_SEPARATOR.'OrdersServiceProvider.php';
        $newProvider = $to.DIRECTORY_SEPARATOR.'Infrastructure'.DIRECTORY_SEPARATOR.'Providers'.DIRECTORY_SEPARATOR.$module.'ServiceProvider.php';

        if ($this->files->exists($oldProvider) && $oldProvider !== $newProvider) {
            if (! @$this->files->move($oldProvider, $newProvider)) {
                $this->files->copy($oldProvider, $newProvider);
                $this->files->delete($oldProvider);
            }
        }
    }

    private function rewriteCopiedFiles(
        string $destination,
        string $module,
        string $kebab,
        string $env,
        string $dirName,
        int $port,
    ): void {
        $replacements = [
            'orders-service' => $dirName,
            'OrdersService' => $module.'Service',
            'Orders' => $module,
            'ORDERS' => $env,
            'orders' => $kebab,
            '8001' => (string) $port,
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if (! $item->isFile() || ! $this->shouldRewrite($item->getPathname())) {
                continue;
            }

            $contents = $this->files->get($item->getPathname());
            $updated = str_replace(array_keys($replacements), array_values($replacements), $contents);

            if ($updated !== $contents) {
                $this->files->put($item->getPathname(), $updated);
            }
        }
    }

    private function shouldRewrite(string $path): bool
    {
        $basename = basename($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($basename, ['composer.lock', 'package-lock.json'], true)) {
            return false;
        }

        if ($ext === '') {
            return in_array($basename, [
                '.env.example',
                '.gitignore',
                '.gitattributes',
                '.editorconfig',
                '.npmrc',
                'artisan',
            ], true);
        }

        return in_array($ext, [
            'php', 'json', 'md', 'example', 'yml', 'yaml', 'xml', 'js', 'css', 'stub', 'txt', 'html',
        ], true);
    }

    private function writeProjectJson(string $destination, string $dirName, string $kebab, int $port): void
    {
        $json = [
            'name' => $dirName,
            '$schema' => '../../node_modules/nx/schemas/project-schema.json',
            'projectType' => 'application',
            'sourceRoot' => 'apps/'.$dirName.'/app',
            'tags' => ['type:microservice', 'stack:laravel', 'domain:'.$kebab],
            'targets' => [
                'serve' => [
                    'executor' => 'nx:run-commands',
                    'options' => [
                        'command' => 'php artisan serve --host=127.0.0.1 --port='.$port,
                        'cwd' => 'apps/'.$dirName,
                    ],
                ],
                'test' => [
                    'executor' => 'nx:run-commands',
                    'options' => [
                        'command' => 'php artisan test',
                        'cwd' => 'apps/'.$dirName,
                    ],
                ],
                'migrate' => [
                    'executor' => 'nx:run-commands',
                    'options' => [
                        'command' => 'php artisan migrate',
                        'cwd' => 'apps/'.$dirName,
                    ],
                ],
                'lint' => [
                    'executor' => 'nx:run-commands',
                    'options' => [
                        'command' => 'php vendor/bin/pint --test',
                        'cwd' => 'apps/'.$dirName,
                    ],
                ],
                'build' => [
                    'executor' => 'nx:run-commands',
                    'cache' => false,
                    'options' => [
                        'command' => 'node infrastructure/scripts/docker-build.mjs apps/'.$dirName,
                        'cwd' => '.',
                    ],
                ],
            ],
        ];

        $this->files->put(
            $destination.DIRECTORY_SEPARATOR.'project.json',
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function writeReadme(
        string $destination,
        string $module,
        string $dirName,
        string $env,
        int $port,
    ): void {
        $contents = <<<MD
# {$module} service (backend)

Internal Laravel microservice. Lives under `apps/{$dirName}`.

Portals must call the gateway (`apps/api`), not this process.

```env
# apps/api/.env
GATEWAY_{$env}_ENABLED=true
{$env}_SERVICE_URL=http://127.0.0.1:{$port}
{$env}_SERVICE_SECRET=
```

## Commands

```bash
cd apps/{$dirName}
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=127.0.0.1 --port={$port}
```

```bash
npx nx serve {$dirName}
```

Liveness: http://127.0.0.1:{$port}/up
MD;

        $this->files->put($destination.DIRECTORY_SEPARATOR.'README.md', $contents."\n");
    }

    private function registerGateway(string $module, string $kebab, string $env, int $port): void
    {
        $path = config_path('gateway.php');
        $contents = $this->files->get($path);

        if (str_contains($contents, "'{$kebab}' =>")) {
            $this->components->warn("Gateway already has [{$kebab}]. Skipped config/gateway.php.");

            return;
        }

        $entry = <<<PHP
        '{$kebab}' => [
            'enabled' => (bool) env('GATEWAY_{$env}_ENABLED', false),
            'prefix' => '{$kebab}',
            'base_url' => env('{$env}_SERVICE_URL', 'http://127.0.0.1:{$port}'),
            'timeout' => (float) env('GATEWAY_{$env}_TIMEOUT', 10),
            'secret' => env('{$env}_SERVICE_SECRET', ''),
            'public' => false,
            'forward_auth' => false,
        ],

PHP;

        $updated = preg_replace(
            "/(\n    \],\n\n    'default_timeout')/",
            "\n".$entry.'$1',
            $contents,
            1,
        );

        if (! is_string($updated) || $updated === $contents) {
            $this->components->warn('Could not patch config/gateway.php automatically. Add the service by hand.');

            return;
        }

        $this->files->put($path, $updated);
        $this->components->info('Registered '.$kebab.' in config/gateway.php (proxy still disabled).');
    }

    private function appendEnvExample(string $env, string $dirName, int $port): void
    {
        $path = base_path('.env.example');

        if (! $this->files->exists($path)) {
            return;
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, "GATEWAY_{$env}_ENABLED")) {
            return;
        }

        $block = <<<ENV

GATEWAY_{$env}_ENABLED=false
{$env}_SERVICE_URL=http://127.0.0.1:{$port}
{$env}_SERVICE_SECRET=
# Docker: {$env}_SERVICE_URL=http://{$dirName}:80
ENV;

        $this->files->put($path, rtrim($contents)."\n".$block."\n");
    }

    private function appendRootNpmScript(string $repoRoot, string $dirName, int $port): void
    {
        $path = $repoRoot.DIRECTORY_SEPARATOR.'package.json';

        if (! $this->files->exists($path)) {
            return;
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, '"'.$dirName.'"')) {
            return;
        }

        $script = '    "'.$dirName.'": "php apps/'.$dirName.'/artisan serve --host=127.0.0.1 --port='.$port.'",';
        $updated = preg_replace(
            '/(    "customer-portal":)/',
            $script."\n$1",
            $contents,
            1,
        );

        if (is_string($updated) && $updated !== $contents) {
            $this->files->put($path, $updated);
        }
    }
}
