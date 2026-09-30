<?php

namespace App\Core\Exceptions;

use Throwable;

class ExternalServiceException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'An external service error occurred.',
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

    public static function forService(string $service, string $message, array $context = []): self
    {
        return new self(
            message: "{$service}: {$message}",
            context: array_merge(['service' => $service], $context),
        );
    }
}
