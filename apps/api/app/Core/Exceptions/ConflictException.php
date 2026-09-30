<?php

namespace App\Core\Exceptions;

use Throwable;

class ConflictException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'The request conflicts with the current state of the resource.',
        int $code = 0,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $context, $previous);
    }

    public function statusCode(): int
    {
        return 409;
    }
}
