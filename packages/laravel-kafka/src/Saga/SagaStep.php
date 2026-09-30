<?php

namespace NestLaravel\Kafka\Saga;

/**
 * A forward action of a saga. It may write to this service's database and publish events through the outbox
 * (both are committed atomically with the saga's own state), but must be safe to run again if the process
 * crashes before that commit.
 */
interface SagaStep
{
    public function execute(SagaContext $context): StepResult;
}
