<?php

namespace App\Infrastructure\Gateway;

use App\Core\Exceptions\ExternalServiceException;

/** A downstream service could not be used right now (circuit open, timeout, unreachable). Never leaks internal URLs. */
final class GatewayUnavailableException extends ExternalServiceException
{
    public function __construct(string $message, private readonly int $status = 502, public readonly ?int $retryAfter = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, [], $previous);
    }

    public function statusCode(): int
    {
        return $this->status;
    }
}
