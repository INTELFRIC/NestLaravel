<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use NestLaravel\Kafka\Inbox\EventInbox;
use NestLaravel\Kafka\KafkaConfig;
use NestLaravel\Kafka\Schema\EventSchemaRegistry;

/** CI gate: every new version of an event must be backward compatible with the previous one. */
class EventsCheckCommand extends Command
{
    protected $signature = 'events:check';

    protected $description = 'Fail if a new event schema version breaks consumers of the previous version';

    public function handle(EventSchemaRegistry $registry): int
    {
        $problems = [];

        foreach ($registry->all() as $type => $versions) {
            $numbers = array_keys($versions);
            sort($numbers);
            for ($i = 1; $i < count($numbers); $i++) {
                foreach ($registry->compatibilityProblems($type, $numbers[$i - 1], $numbers[$i]) as $p) {
                    $problems[] = "{$type}: {$p}";
                }
            }
        }

        foreach ($problems as $p) {
            $this->error($p);
        }
        $problems === [] && $this->info('All event schema versions are backward compatible.');

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
