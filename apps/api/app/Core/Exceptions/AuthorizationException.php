<?php

namespace App\Core\Exceptions;

use Throwable;

/**
 * Domain-level authorization failure.
 *
 * Distinct from Illuminate\Auth\Access\AuthorizationException so domain
 * and application layers can throw without coupling to the HTTP auth stack.
 */
class AuthorizationException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'This action is unauthorized.',
        int $code = 0,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $context, $previous);
    }

    public function statusCode(): int
    {
        return 403;
    }
}
