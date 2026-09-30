<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use NestLaravel\Kafka\Schema\EventSchemaRegistry;

/** Registered event types and schema versions. */
class EventsListCommand extends Command
{
    protected $signature = 'events:list {--json}';

    protected $description = 'List registered domain events with schema versions and required payload fields';

    public function handle(EventSchemaRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->all() as $type => $versions) {
            foreach ($versions as $version => $schema) {
                $rows[] = ['type' => $type, 'version' => $version, 'class' => $registry->classes()[$type.'@'.$version] ?? '-', 'required' => $schema->requiredFields(), 'fields' => $schema->fields()];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_UNESCAPED_SLASHES));
        } elseif ($rows === []) {
            $this->warn('No events registered. Generate one with: nestlaravel generate event <type> --service <name>');
        } else {
            $this->table(['type', 'v', 'class', 'required fields'], array_map(static fn ($r) => [$r['type'], $r['version'], $r['class'], implode(', ', $r['required'])], $rows));
        }

        return self::SUCCESS;
    }
}
