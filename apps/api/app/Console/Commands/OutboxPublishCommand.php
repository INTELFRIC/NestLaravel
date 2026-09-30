<?php

namespace App\Console\Commands;

use App\Infrastructure\Kafka\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

class OutboxPublishCommand extends Command
{
    protected $signature = 'messaging:outbox-publish
                            {--limit= : Max pending outbox rows to process}
                            {--daemon : Keep polling until SIGTERM/SIGINT (run as a supervised process)}
                            {--sleep=1 : Seconds to wait between polls in daemon mode}';

    protected $description = 'Publish pending outbox messages to Kafka';

    private bool $shouldStop = false;

    public function handle(OutboxPublisher $publisher): int
    {
        $limit = $this->option('limit');
        $limit = $limit !== null && $limit !== '' ? (int) $limit : null;

        if (! $this->option('daemon')) {
            $this->info('Published '.$publisher->publishPending($limit).' outbox message(s).');

            return self::SUCCESS;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->shouldStop = true;
                });
            }
        }

        $this->info('Outbox publisher running (daemon).');

        while (! $this->shouldStop) {
            if ($publisher->publishPending($limit) === 0) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        }

        $this->info('Outbox publisher stopped.');

        return self::SUCCESS;
    }
}
