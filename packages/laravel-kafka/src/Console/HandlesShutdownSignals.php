<?php

namespace NestLaravel\Kafka\Console;

/**
 * Cooperative SIGTERM/SIGINT handling for long-running commands: the signal only raises a flag, the loop finishes the
 * unit of work in progress (message / batch) and exits cleanly.
 *
 * Uses Laravel's trap() when available: it registers through Symfony Console's signal registry, which owns the process
 * signal table while a command runs. A raw pcntl_signal() next to that registry can be replaced or bypassed (Symfony's own
 * handler may exit the process), so it is only the fallback.
 */
trait HandlesShutdownSignals
{
    private bool $shouldStop = false;

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        $stop = function (): void {
            $this->shouldStop = true;
        };

        if (method_exists($this, 'trap')) {
            $this->trap([SIGTERM, SIGINT], $stop);

            return;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, $stop);
        }
    }
}
