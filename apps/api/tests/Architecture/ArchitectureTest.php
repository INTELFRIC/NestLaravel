<?php

namespace Tests\Architecture;

use Tests\TestCase;

class ArchitectureTest extends TestCase
{
    public function test_enterprise_root_directories_exist(): void
    {
        $this->assertDirectoryExists(base_path('app/Modules'));
        $this->assertDirectoryExists(base_path('app/Core'));
        $this->assertDirectoryExists(base_path('app/Infrastructure'));
        $this->assertDirectoryExists(base_path('app/Messaging'));
    }

    public function test_enterprise_supporting_directories_exist(): void
    {
        $this->assertDirectoryExists(base_path('app/Security'));
        $this->assertDirectoryExists(base_path('app/Observability'));
        $this->assertDirectoryExists(base_path('stubs/enterprise'));

        // Platform docs live at the monorepo root (Nest-Laravel/docs).
        $monorepoDocs = dirname(base_path(), 2).DIRECTORY_SEPARATOR.'docs';
        $this->assertDirectoryExists($monorepoDocs);
    }

    public function test_dx_artisan_command_classes_exist(): void
    {
        $this->assertFileExists(base_path('app/Console/Commands/MakeModuleCommand.php'));
        $this->assertFileExists(base_path('app/Console/Commands/MakeActionCommand.php'));
        $this->assertFileExists(base_path('app/Console/Commands/MakeDtoCommand.php'));
        $this->assertFileExists(base_path('app/Console/Commands/MakeDomainEventCommand.php'));
        $this->assertFileExists(base_path('app/Console/Commands/MakeConsumerCommand.php'));
    }
}
