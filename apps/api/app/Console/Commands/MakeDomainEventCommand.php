<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesEnterpriseModule;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class MakeDomainEventCommand extends GeneratorCommand
{
    use ResolvesEnterpriseModule;

    protected $name = 'make:domain-event';

    protected $description = 'Create a new domain event extending AbstractDomainEvent';

    protected $type = 'Domain event';

    protected function getStub(): string
    {
        return base_path('stubs/enterprise/domain-event.stub');
    }

    protected function getNameInput(): string
    {
        return Str::studly(parent::getNameInput());
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        $module = $this->resolveModuleName();

        return $this->moduleNamespace($module, 'Domain/Events');
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);
        $module = $this->resolveModuleName();
        $class = class_basename($name);

        $aggregate = Str::snake(preg_replace('/(Created|Updated|Deleted)$/', '', $class) ?: $class);
        $aggregate = $aggregate !== '' ? $aggregate : Str::snake($module);
        $aggregateType = Str::replace('_', '-', $aggregate);

        $action = match (true) {
            Str::endsWith($class, 'Created') => 'created',
            Str::endsWith($class, 'Updated') => 'updated',
            Str::endsWith($class, 'Deleted') => 'deleted',
            default => Str::kebab($class),
        };

        $eventType = Str::kebab($module).'.'.$aggregateType.'.'.$action;

        return str_replace(
            ['{{ eventType }}', '{{ aggregateType }}'],
            [$eventType, $aggregateType],
            $stub,
        );
    }

    protected function getOptions(): array
    {
        return [
            ['module', null, InputOption::VALUE_REQUIRED, 'The target module (e.g. Orders)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the event already exists'],
        ];
    }
}
