<?php

namespace NestLaravel\Kafka\Saga;

use InvalidArgumentException;

/**
 * Fluent saga description:
 *
 *   Saga::define('order_checkout')
 *       ->step(ReserveInventory::class)->compensate(ReleaseInventory::class)
 *       ->step(ProcessPayment::class)->compensate(RefundPayment::class)->retries(2)
 *       ->step(ConfirmOrder::class);
 */
final class SagaDefinition
{
    /** @var list<array{step: class-string<SagaStep>, compensation: ?class-string<Compensation>, retries: int}> */
    private array $steps = [];

    public function __construct(public readonly string $name, public readonly int $defaultRetries = 2) {}

    /** @param class-string<SagaStep> $class */
    public function step(string $class): self
    {
        if (! is_subclass_of($class, SagaStep::class)) {
            throw new InvalidArgumentException("[{$class}] must implement ".SagaStep::class);
        }

        $this->steps[] = ['step' => $class, 'compensation' => null, 'retries' => $this->defaultRetries];

        return $this;
    }

    /** @param class-string<Compensation> $class compensation for the step declared just before */
    public function compensate(string $class): self
    {
        if ($this->steps === []) {
            throw new InvalidArgumentException('compensate() must follow step().');
        }
        if (! is_subclass_of($class, Compensation::class)) {
            throw new InvalidArgumentException("[{$class}] must implement ".Compensation::class);
        }

        $this->steps[array_key_last($this->steps)]['compensation'] = $class;

        return $this;
    }

    /** Retries (after the first attempt) when the step THROWS. Business failures use StepResult::fail() and never retry. */
    public function retries(int $retries): self
    {
        if ($this->steps === []) {
            throw new InvalidArgumentException('retries() must follow step().');
        }

        $this->steps[array_key_last($this->steps)]['retries'] = max(0, $retries);

        return $this;
    }

    /** @return list<array{step: class-string<SagaStep>, compensation: ?class-string<Compensation>, retries: int}> */
    public function steps(): array
    {
        return $this->steps;
    }
}
