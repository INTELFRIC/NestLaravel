<?php

namespace App\Core\Exceptions;

use Exception;
use Throwable;

class DomainException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        protected array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function statusCode(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return class_basename(static::class);
    }
}
