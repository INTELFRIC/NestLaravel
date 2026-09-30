<?php

namespace App\Mcp\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class ModuleCatalog
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return list<array{
     *     name: string,
     *     path: string,
     *     provider: string|null,
     *     routes: string|null,
     *     layers: list<string>
     * }>
     */
    public function all(): array
    {
        $modulesPath = app_path('Modules');

        if (! $this->files->isDirectory($modulesPath)) {
            return [];
        }

        $modules = [];

        foreach ($this->files->directories($modulesPath) as $directory) {
            $name = basename($directory);
            $modules[] = $this->describe($name);
        }

        usort($modules, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $modules;
    }

    /**
     * @return array{
     *     name: string,
     *     path: string,
     *     provider: string|null,
     *     routes: string|null,
     *     layers: list<string>,
     *     files: list<string>
     * }|null
     */
    public function find(string $name): ?array
    {
        $module = Str::studly(trim($name));

        if ($module === '' || ! $this->files->isDirectory(app_path('Modules/'.$module))) {
            return null;
        }

        $summary = $this->describe($module);
        $summary['files'] = $this->phpFiles($module);

        return $summary;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->all(), 'name');
    }

    /**
     * @return array{
     *     name: string,
     *     path: string,
     *     provider: string|null,
     *     routes: string|null,
     *     layers: list<string>
     * }
     */
    private function describe(string $module): array
    {
        $relative = 'Modules/'.$module;
        $provider = "app/{$relative}/Infrastructure/Providers/{$module}ServiceProvider.php";
        $routes = "app/{$relative}/Presentation/Routes/api.php";

        return [
            'name' => $module,
            'path' => 'app/'.$relative,
            'provider' => $this->files->exists(base_path($provider)) ? $provider : null,
            'routes' => $this->files->exists(base_path($routes)) ? $routes : null,
            'layers' => array_values(array_filter(
                ['Domain', 'Application', 'Infrastructure', 'Presentation'],
                fn (string $layer): bool => $this->files->isDirectory(app_path("{$relative}/{$layer}")),
            )),
        ];
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $module): array
    {
        $root = app_path('Modules/'.$module);
        $files = [];

        foreach ($this->files->allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files[] = Str::of($file->getPathname())
                ->replace('\\', '/')
                ->after('app/Modules/')
                ->prepend('app/Modules/')
                ->toString();
        }

        sort($files);

        return $files;
    }
}
