<?php

namespace NestLaravel\Kafka\Resilience;

use RuntimeException;

/** Transport-level failure (timeout, connection refused, DNS) after all permitted retries. Map to 502/504. */
class UpstreamUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $circuit, string $message, ?\Throwable $previous = null)
    {
        parent::__construct("Upstream [{$circuit}] unreachable: {$message}", 0, $previous);
    }
}
