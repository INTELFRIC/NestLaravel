<?php

namespace NestLaravel\Kafka\Saga;

/** What a step reports back to the orchestrator. */
final class StepResult
{
    /**
     * @param  array<string, mixed>  $data  merged into the saga context
     * @param  list<string>  $failureEvents  event types that mean "this step failed remotely" while waiting
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $data = [],
        public readonly ?string $waitFor = null,
        public readonly array $failureEvents = [],
        public readonly ?int $timeoutSeconds = null,
    ) {}

    /** The step finished synchronously; continue with the next one. */
    public static function done(array $data = []): self
    {
        return new self('done', $data);
    }

    /**
     * The step started remote work (usually by publishing a command/event). Pause until `$successEvent` arrives with
     * this saga's correlation id; any of `$failureEvents` triggers compensation; nothing within `$timeoutSeconds`
     * also triggers compensation.
     *
     * @param  list<string>  $failureEvents
     */
    public static function waitFor(string $successEvent, array $failureEvents = [], ?int $timeoutSeconds = 300, array $data = []): self
    {
        return new self('wait', $data, $successEvent, $failureEvents, $timeoutSeconds);
    }

    /** Abort now and compensate (a business decision, not an exception — no retries). */
    public static function fail(string $reason): self
    {
        return new self('fail', ['reason' => $reason]);
    }
}
