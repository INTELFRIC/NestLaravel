<?php

namespace NestLaravel\Kafka\Exceptions;

/**
 * Marker for handler exceptions that will never succeed on retry (business rule violated, entity gone, …).
 * The consumer dead-letters the message immediately instead of burning retries.
 *
 *   final class OrderAlreadyShipped extends \DomainException implements NonRetryable {}
 */
interface NonRetryable extends \Throwable
{
}
