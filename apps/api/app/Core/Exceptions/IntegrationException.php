<?php

namespace App\Core\Exceptions;

use Throwable;

class IntegrationException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'An integration error occurred.',
        int $code = 0,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $context, $previous);
    }

    public function statusCode(): int
    {
        return 502;
    }
}
