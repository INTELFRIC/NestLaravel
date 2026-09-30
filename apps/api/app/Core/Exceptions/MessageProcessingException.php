<?php

namespace App\Core\Exceptions;

use Throwable;

class MessageProcessingException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Failed to process message.',
        int $code = 0,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $context, $previous);
    }

    public function statusCode(): int
    {
        return 500;
    }

    public static function forEvent(string $eventType, string $message, array $context = []): self
    {
        return new self(
            message: $message,
            context: array_merge(['event_type' => $eventType], $context),
        );
    }
}
