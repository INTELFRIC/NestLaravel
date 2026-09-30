<?php

namespace NestLaravel\Kafka\Console;

/**
 * Cooperative SIGTERM/SIGINT handling for long-running commands: the signal only raises a flag, the loop finishes the
 * unit of work in progress (message / batch) and exits cleanly.
 *
 * Plain pcntl handlers (async signals): verified on Linux CI by GracefulShutdownTest. Laravel's Command::trap() was
 * tried and rejected: in the test kernel it left SIGTERM with its default action and killed the process.
 */
trait HandlesShutdownSignals
{
    private bool $shouldStop = false;

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldStop = true;
            });
        }
    }
}
