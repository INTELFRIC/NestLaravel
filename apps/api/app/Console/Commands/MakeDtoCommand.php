<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesEnterpriseModule;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class MakeDtoCommand extends GeneratorCommand
{
    use ResolvesEnterpriseModule;

    protected $name = 'make:dto';

    protected $description = 'Create a new application DTO class inside a module';

    protected $type = 'DTO';

    protected function getStub(): string
    {
        return base_path('stubs/enterprise/dto.stub');
    }

    protected function getNameInput(): string
    {
        return Str::studly(parent::getNameInput());
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        $module = $this->resolveModuleName();

        return $this->moduleNamespace($module, 'Application/DTOs');
    }

    protected function getOptions(): array
    {
        return [
            ['module', null, InputOption::VALUE_REQUIRED, 'The target module (e.g. Orders)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the DTO already exists'],
        ];
    }
}
