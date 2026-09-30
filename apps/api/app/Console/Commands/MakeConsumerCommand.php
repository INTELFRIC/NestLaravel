<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesEnterpriseModule;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class MakeConsumerCommand extends GeneratorCommand
{
    use ResolvesEnterpriseModule;

    protected $name = 'make:consumer';

    protected $description = 'Create a new Kafka/integration event consumer inside a module';

    protected $type = 'Consumer';

    protected function getStub(): string
    {
        return base_path('stubs/enterprise/consumer.stub');
    }

    protected function getNameInput(): string
    {
        return Str::studly(parent::getNameInput());
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        $module = $this->resolveModuleName();

        return $this->moduleNamespace($module, 'Infrastructure/Consumers');
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $module = $this->resolveModuleName();
        $class = class_basename($name);
        $base = Str::replaceLast('Consumer', '', $class);
        $eventType = Str::kebab($module).'.'.Str::kebab($base);

        return str_replace(
            ['{{ eventType }}', '{{ module }}', '{{ class }}'],
            [$eventType, $module, $class],
            $stub,
        );
    }

    protected function getOptions(): array
    {
        return [
            ['module', null, InputOption::VALUE_REQUIRED, 'The target module (e.g. Orders)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the consumer already exists'],
        ];
    }
}
