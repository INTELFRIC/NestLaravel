<?php

namespace NestLaravel\Kafka\Resilience;

use RuntimeException;

/** The breaker for this upstream is open: the request was NOT sent. Map to HTTP 503 + Retry-After. */
class CircuitOpenException extends RuntimeException
{
    public function __construct(public readonly string $circuit, public readonly int $retryAfter)
    {
        parent::__construct("Circuit [{$circuit}] is open; upstream is unavailable. Retry in {$retryAfter}s.");
    }
}
