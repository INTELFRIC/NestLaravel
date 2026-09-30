<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use NestLaravel\Kafka\Outbox\OutboxMessage;
use NestLaravel\Kafka\Outbox\OutboxPublisher;

/** Outbox backlog, failures, and re-queueing of failed rows. */
class OutboxStatusCommand extends Command
{
    protected $signature = 'outbox:status {--json} {--failed : List failed rows} {--requeue : Put ALL failed rows back to pending} {--limit=20}';

    protected $description = 'Show transactional outbox state (pending / processing / failed / oldest age)';

    public function handle(OutboxPublisher $publisher): int
    {
        if ($this->option('requeue')) {
            $n = OutboxMessage::query()->where('status', OutboxMessage::STATUS_FAILED)->update([
                'status' => OutboxMessage::STATUS_PENDING, 'attempts' => 0, 'available_at' => now(), 'locked_by' => null, 'locked_at' => null,
            ]);
            $this->info("Requeued {$n} failed row(s).");
        }

        $snapshot = $publisher->snapshot();
        $failed = $this->option('failed')
            ? OutboxMessage::query()->where('status', OutboxMessage::STATUS_FAILED)->orderByDesc('id')->limit((int) $this->option('limit'))
                ->get(['id', 'event_id', 'event_type', 'attempts', 'last_error', 'updated_at'])->toArray()
            : [];

        if ($this->option('json')) {
            $this->line(json_encode(['status' => $snapshot, 'failed' => $failed], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['pending', 'processing', 'published', 'failed', 'oldest pending (s)', 'oldest processing (s)'], [[
            $snapshot['pending'], $snapshot['processing'], $snapshot['published'], $snapshot['failed'],
            $snapshot['oldest_pending_age_seconds'] ?? '-', $snapshot['oldest_processing_age_seconds'] ?? '-',
        ]]);

        if ($failed !== []) {
            $this->table(['id', 'event_id', 'type', 'attempts', 'last error'], array_map(static fn ($r) => [$r['id'], $r['event_id'], $r['event_type'], $r['attempts'], mb_substr((string) $r['last_error'], 0, 80)], $failed));
        }

        return self::SUCCESS;
    }
}
