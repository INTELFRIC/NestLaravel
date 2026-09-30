<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use NestLaravel\Kafka\Inbox\EventInbox;
use NestLaravel\Kafka\KafkaConfig;

class InboxPruneCommand extends Command
{
    protected $signature = 'inbox:prune {--days= : Retention in days (default kafka.inbox.retention_days)}';

    protected $description = 'Delete inbox (dedup) records older than the retention period';

    public function handle(EventInbox $inbox, KafkaConfig $config): int
    {
        $days = (int) ($this->option('days') ?: $config->inboxRetentionDays());
        $this->info("Pruned {$inbox->prune($days)} inbox record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
