<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use NestLaravel\Kafka\Saga\SagaOrchestrator;

class SagaRecoverCommand extends Command
{
    protected $signature = 'saga:recover';

    protected $description = 'Compensate timed-out sagas and resume sagas whose runner crashed (schedule every minute)';

    public function handle(SagaOrchestrator $orchestrator): int
    {
        $this->info('Recovered/acted on '.$orchestrator->recoverOverdue().' saga(s).');

        return self::SUCCESS;
    }
}
