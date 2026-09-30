<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MakeMicroserviceCommandTest extends TestCase
{
    public function test_it_scaffolds_a_service_under_apps_and_registers_the_gateway(): void
    {
        $repoRoot = dirname(base_path(), 2);
        $serviceDir = $repoRoot.DIRECTORY_SEPARATOR.'apps'.DIRECTORY_SEPARATOR.'tmp-catalog-service';
        $gateway = config_path('gateway.php');
        $envExample = base_path('.env.example');
        $packageJson = $repoRoot.DIRECTORY_SEPARATOR.'package.json';

        $gatewayBackup = File::get($gateway);
        $envBackup = File::get($envExample);
        $packageBackup = File::get($packageJson);

        try {
            $this->artisan('make:microservice', [
                'name' => 'TmpCatalog',
                '--port' => '8099',
                '--force' => true,
            ])->assertSuccessful();

            $this->assertDirectoryExists($serviceDir);
            $this->assertFileExists($serviceDir.DIRECTORY_SEPARATOR.'project.json');
            $this->assertFileExists($serviceDir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules'.DIRECTORY_SEPARATOR.'TmpCatalog'.DIRECTORY_SEPARATOR.'Infrastructure'.DIRECTORY_SEPARATOR.'Providers'.DIRECTORY_SEPARATOR.'TmpCatalogServiceProvider.php');

            $project = File::get($serviceDir.DIRECTORY_SEPARATOR.'project.json');
            $this->assertStringContainsString('"name": "tmp-catalog-service"', $project);
            $this->assertStringContainsString('--port=8099', $project);

            $this->assertStringContainsString("'tmp-catalog' =>", File::get($gateway));
            $this->assertStringContainsString('GATEWAY_TMP_CATALOG_ENABLED=false', File::get($envExample));
            // The root npm script shortcut is only added to workspaces that have the portal scripts (framework repo).
            if (str_contains($packageBackup, '"customer-portal":')) {
                $this->assertStringContainsString('"tmp-catalog-service":', File::get($packageJson));
            }
        } finally {
            File::deleteDirectory($serviceDir);
            File::put($gateway, $gatewayBackup);
            File::put($envExample, $envBackup);
            File::put($packageJson, $packageBackup);
        }
    }

    public function test_it_rejects_gateway_owned_names(): void
    {
        $this->artisan('make:microservice', ['name' => 'Auth'])
            ->assertFailed();
    }
}
