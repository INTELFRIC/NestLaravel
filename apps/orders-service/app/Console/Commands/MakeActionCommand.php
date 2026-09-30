<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesEnterpriseModule;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class MakeActionCommand extends GeneratorCommand
{
    use ResolvesEnterpriseModule;

    protected $name = 'make:action';

    protected $description = 'Create a new application Action class inside a module';

    protected $type = 'Action';

    protected function getStub(): string
    {
        return base_path('stubs/enterprise/action.stub');
    }

    protected function getNameInput(): string
    {
        return Str::studly(parent::getNameInput());
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        $module = $this->resolveModuleName();

        return $this->moduleNamespace($module, 'Application/Actions');
    }

    protected function getOptions(): array
    {
        return [
            ['module', null, InputOption::VALUE_REQUIRED, 'The target module (e.g. Orders)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the action already exists'],
        ];
    }
}
