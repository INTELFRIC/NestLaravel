<?php

namespace NestLaravel\Kafka\Saga;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NestLaravel\Kafka\Observability\LogContext;
use NestLaravel\Kafka\Observability\Metrics;
use Throwable;

/**
 * Orchestrated saga runner (state in this service's database; steps talk to other services through events).
 *
 * Guarantees, each covered by tests:
 *  • start() is idempotent per (name, correlation_id) – a duplicate command does not start a second saga
 *  • every step runs in a DB transaction together with its state change and any outbox events it publishes
 *  • a crash mid-step re-runs that step after recovery (steps must tolerate re-execution)
 *  • events that resume a saga are idempotent: a duplicate finds the saga no longer waiting for them and is ignored
 *  • failure event / timeout / exhausted retries / StepResult::fail() ⇒ completed steps are compensated in REVERSE
 *  • a compensation that keeps failing parks the saga in `failed` (loud log + metric) instead of guessing
 *  • two workers never advance the same saga concurrently (claim via locked_at, portable across databases)
 */
final class SagaOrchestrator
{
    private const STALE_LOCK_SECONDS = 120;

    public function __construct(private readonly int $compensationRetries = 3) {}

    /** @param array<string, mixed> $context */
    public function start(string $name, array $context = [], ?string $correlationId = null): SagaInstance
    {
        Saga::definition($name);   // fail fast on typos
        $correlationId ??= app()->bound('correlation_id') ? (string) app('correlation_id') : (string) Str::uuid();

        $created = SagaInstance::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'correlation_id' => $correlationId,
            'status' => SagaInstance::RUNNING,
            'step_index' => 0,
            'attempts' => 0,
            'context' => json_encode($context),
            'history' => json_encode([['at' => now()->toIso8601String(), 'event' => 'started']]),
            'tenant_id' => app()->bound('tenant_id') ? (string) app('tenant_id') : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $instance = SagaInstance::query()->where('name', $name)->where('correlation_id', $correlationId)->firstOrFail();

        if ($created === 1) {
            Metrics::inc('nestlaravel_saga_total', ['saga' => $name, 'result' => 'started'], help: 'Saga lifecycle events');
            $this->advance($instance);
        }

        return $instance->refresh();
    }

    /**
     * Feed an incoming event (from a Kafka consumer) to the sagas waiting for it.
     *
     * @param  array<string, mixed>  $payload
     * @return int number of sagas that reacted (0 = duplicate or unrelated)
     */
    public function handleEvent(string $eventType, array $payload, string $correlationId): int
    {
        $reacted = 0;

        foreach (SagaInstance::query()->where('correlation_id', $correlationId)->where('status', SagaInstance::WAITING)->get() as $instance) {
            if (! $this->claim($instance)) {
                continue;
            }

            $instance->refresh();

            try {
                if ($instance->status !== SagaInstance::WAITING) {
                    continue; // another worker/duplicate already moved it on
                }

                if ($instance->waiting_for === $eventType) {
                    $instance->status = SagaInstance::RUNNING;
                    $instance->waiting_for = null;
                    $instance->deadline_at = null;
                    $instance->context = array_merge($instance->context, ['_event' => $payload]);
                    $instance->step_index++;
                    $instance->attempts = 0;
                    $instance->record(['event' => 'resumed', 'by' => $eventType]);
                    $instance->save();
                    $reacted++;
                    $this->advance($instance, $payload, alreadyClaimed: true);
                } elseif (in_array($eventType, (array) $instance->failure_events, true)) {
                    $instance->record(['event' => 'failure_event', 'by' => $eventType]);
                    $instance->save();
                    $reacted++;
                    $this->compensate($instance, "Failure event [{$eventType}] received.", includeCurrent: true, alreadyClaimed: true);
                }
            } finally {
                $this->release($instance);
            }
        }

        return $reacted;
    }

    /** Timeouts + crash recovery. Run every minute: `php artisan saga:recover`. @return int instances acted on */
    public function recoverOverdue(): int
    {
        $acted = 0;

        foreach (SagaInstance::query()->where('status', SagaInstance::WAITING)->where('deadline_at', '<', now())->get() as $instance) {
            if ($this->claim($instance)) {
                $instance->refresh();
                if ($instance->status === SagaInstance::WAITING) {
                    Metrics::inc('nestlaravel_saga_total', ['saga' => $instance->name, 'result' => 'timed_out'], help: 'Saga lifecycle events');
                    $this->compensate($instance, "Timed out waiting for [{$instance->waiting_for}].", includeCurrent: true, alreadyClaimed: true);
                    $acted++;
                }
                $this->release($instance);
            }
        }

        // Runner died mid-flight (lock older than the stale threshold) → resume where it stopped.
        foreach (SagaInstance::query()->whereIn('status', [SagaInstance::RUNNING, SagaInstance::COMPENSATING])->where('locked_at', '<', now()->subSeconds(self::STALE_LOCK_SECONDS))->get() as $instance) {
            if ($instance->status === SagaInstance::RUNNING) {
                $this->advance($instance);
            } else {
                $this->compensate($instance, (string) $instance->last_error, includeCurrent: false);
            }
            $acted++;
        }

        // Running/compensating sagas that were never claimed (crash before the first claim).
        foreach (SagaInstance::query()->whereIn('status', [SagaInstance::RUNNING, SagaInstance::COMPENSATING])->whereNull('locked_at')->where('updated_at', '<', now()->subSeconds(self::STALE_LOCK_SECONDS))->get() as $instance) {
            $instance->status === SagaInstance::RUNNING ? $this->advance($instance) : $this->compensate($instance, (string) $instance->last_error, includeCurrent: false);
            $acted++;
        }

        return $acted;
    }

    // ------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $event */
    private function advance(SagaInstance $instance, array $event = [], bool $alreadyClaimed = false): void
    {
        if (! $alreadyClaimed && ! $this->claim($instance)) {
            return;
        }

        LogContext::set(['correlation_id' => $instance->correlation_id]);

        try {
            $definition = Saga::definition($instance->name);
            $steps = $definition->steps();

            while (true) {
                $instance->refresh();

                if ($instance->status !== SagaInstance::RUNNING) {
                    return;
                }

                if ($instance->step_index >= count($steps)) {
                    $instance->status = SagaInstance::COMPLETED;
                    $instance->record(['event' => 'completed']);
                    $instance->save();
                    Metrics::inc('nestlaravel_saga_total', ['saga' => $instance->name, 'result' => 'completed'], help: 'Saga lifecycle events');
                    Log::info('Saga completed', ['saga' => $instance->name, 'saga_id' => $instance->id]);

                    return;
                }

                $spec = $steps[$instance->step_index];

                try {
                    $outcome = DB::transaction(function () use ($instance, $spec, $event) {
                        $ctx = new SagaContext($instance->id, $instance->name, $instance->correlation_id, $instance->context, $event, $instance->attempts + 1);
                        $result = app($spec['step'])->execute($ctx);
                        $instance->context = array_merge($ctx->all(), $result->data);
                        $instance->last_error = null;

                        match ($result->kind) {
                            'done' => $this->markStepDone($instance, $spec),
                            'wait' => $this->markWaiting($instance, $spec, $result),
                            default => $instance->record(['event' => 'step_failed', 'step' => $spec['step'], 'reason' => $result->data['reason'] ?? 'failed']),
                        };
                        $instance->save();

                        return $result;
                    });
                } catch (Throwable $e) {
                    $instance->refresh();
                    $instance->attempts++;
                    $instance->last_error = mb_substr($e->getMessage(), 0, 1000);
                    $instance->record(['event' => 'step_error', 'step' => $spec['step'], 'attempt' => $instance->attempts, 'error' => $instance->last_error]);
                    $instance->save();
                    Log::warning('Saga step failed', ['saga' => $instance->name, 'step' => $spec['step'], 'attempt' => $instance->attempts, 'error' => $e->getMessage()]);

                    if ($instance->attempts > $spec['retries']) {
                        $this->compensate($instance, "Step [{$spec['step']}] failed: {$e->getMessage()}", includeCurrent: false, alreadyClaimed: true);

                        return;
                    }

                    continue; // retry the same step
                }

                if ($outcome->kind === 'wait') {
                    return;
                }
                if ($outcome->kind === 'fail') {
                    $this->compensate($instance, (string) ($outcome->data['reason'] ?? 'Step failed.'), includeCurrent: false, alreadyClaimed: true);

                    return;
                }

                $event = [];
            }
        } finally {
            if (! $alreadyClaimed) {
                $this->release($instance);
            }
        }
    }

    /** @param array{step: class-string, compensation: ?class-string, retries: int} $spec */
    private function markStepDone(SagaInstance $instance, array $spec): void
    {
        $instance->record(['event' => 'step_done', 'step' => $spec['step']]);
        $instance->step_index++;
        $instance->attempts = 0;
    }

    /** @param array{step: class-string, compensation: ?class-string, retries: int} $spec */
    private function markWaiting(SagaInstance $instance, array $spec, StepResult $result): void
    {
        $instance->status = SagaInstance::WAITING;
        $instance->waiting_for = $result->waitFor;
        $instance->failure_events = $result->failureEvents;
        $instance->deadline_at = $result->timeoutSeconds ? now()->addSeconds($result->timeoutSeconds) : null;
        $instance->record(['event' => 'waiting', 'step' => $spec['step'], 'for' => $result->waitFor]);
    }

    private function compensate(SagaInstance $instance, string $reason, bool $includeCurrent, bool $alreadyClaimed = false): void
    {
        if (! $alreadyClaimed && ! $this->claim($instance)) {
            return;
        }

        try {
            $instance->refresh();

            if ($instance->isFinished()) {
                return;
            }

            $steps = Saga::definition($instance->name)->steps();

            if ($instance->status !== SagaInstance::COMPENSATING) {
                $instance->status = SagaInstance::COMPENSATING;
                $instance->waiting_for = null;
                $instance->deadline_at = null;
                $instance->last_error = mb_substr($reason, 0, 1000);
                $instance->record(['event' => 'compensating', 'reason' => $reason]);
                $instance->save();
                Log::warning('Saga compensating', ['saga' => $instance->name, 'saga_id' => $instance->id, 'reason' => $reason]);
            }

            // Steps to undo: everything that completed (+ the current step if it had already started remote work).
            $upTo = $includeCurrent ? $instance->step_index : $instance->step_index - 1;
            $done = $instance->context['_compensated'] ?? [];

            for ($i = min($upTo, count($steps) - 1); $i >= 0; $i--) {
                $compensation = $steps[$i]['compensation'];

                if ($compensation === null || in_array($i, $done, true)) {
                    continue;
                }

                if (! $this->runCompensation($instance, $compensation, $i)) {
                    $instance->status = SagaInstance::FAILED;
                    $instance->record(['event' => 'compensation_failed', 'step' => $steps[$i]['step']]);
                    $instance->save();
                    Metrics::inc('nestlaravel_saga_total', ['saga' => $instance->name, 'result' => 'failed'], help: 'Saga lifecycle events');
                    Log::critical('Saga compensation failed; manual intervention required', ['saga' => $instance->name, 'saga_id' => $instance->id, 'step' => $steps[$i]['step'], 'error' => $instance->last_error]);

                    return;
                }

                $done[] = $i;
                $instance->context = array_merge($instance->context, ['_compensated' => $done]);
                $instance->save();
            }

            $instance->status = SagaInstance::COMPENSATED;
            $instance->record(['event' => 'compensated']);
            $instance->save();
            Metrics::inc('nestlaravel_saga_total', ['saga' => $instance->name, 'result' => 'compensated'], help: 'Saga lifecycle events');
        } finally {
            if (! $alreadyClaimed) {
                $this->release($instance);
            }
        }
    }

    /** @param class-string<Compensation> $class */
    private function runCompensation(SagaInstance $instance, string $class, int $stepIndex): bool
    {
        for ($attempt = 1; $attempt <= max(1, $this->compensationRetries); $attempt++) {
            try {
                DB::transaction(function () use ($instance, $class, $attempt) {
                    $ctx = new SagaContext($instance->id, $instance->name, $instance->correlation_id, $instance->context, [], $attempt);
                    app($class)->compensate($ctx);
                });
                $instance->record(['event' => 'compensated_step', 'step_index' => $stepIndex, 'compensation' => $class]);

                return true;
            } catch (Throwable $e) {
                $instance->last_error = mb_substr("Compensation [{$class}] failed: ".$e->getMessage(), 0, 1000);
                Log::warning('Saga compensation attempt failed', ['saga' => $instance->name, 'compensation' => $class, 'attempt' => $attempt, 'error' => $e->getMessage()]);
            }
        }

        return false;
    }

    /** Portable exclusive claim: only one worker holds a saga at a time. */
    private function claim(SagaInstance $instance): bool
    {
        return SagaInstance::query()->whereKey($instance->id)
            ->where(fn ($q) => $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subSeconds(self::STALE_LOCK_SECONDS)))
            ->update(['locked_at' => now()]) === 1;
    }

    private function release(SagaInstance $instance): void
    {
        SagaInstance::query()->whereKey($instance->id)->update(['locked_at' => null]);
    }
}
