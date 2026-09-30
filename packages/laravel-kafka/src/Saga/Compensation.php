<?php

namespace NestLaravel\Kafka\Saga;

/** Undoes the effect of a step that already completed. Must be idempotent (it can be retried). */
interface Compensation
{
    public function compensate(SagaContext $context): void;
}
