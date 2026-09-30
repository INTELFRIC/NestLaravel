<?php

namespace App\Core\Exceptions;

use Throwable;

class ResourceNotFoundException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Resource not found.',
        int $code = 0,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $context, $previous);
    }

    public function statusCode(): int
    {
        return 404;
    }

    public static function for(string $resource, string|int|null $id = null): self
    {
        $message = $id === null
            ? "{$resource} not found."
            : "{$resource} [{$id}] not found.";

        return new self($message, context: [
            'resource' => $resource,
            'id' => $id,
        ]);
    }
}
