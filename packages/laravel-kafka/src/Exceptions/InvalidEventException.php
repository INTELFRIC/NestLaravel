<?php

namespace NestLaravel\Kafka\Exceptions;

use InvalidArgumentException;

/**
 * The event (envelope or payload) is malformed or violates its schema. Retrying can never fix it, so the
 * consumer pipeline dead-letters it immediately ("poison message").
 */
class InvalidEventException extends InvalidArgumentException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
