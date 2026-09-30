<?php

namespace NestLaravel\Tenancy\Exceptions;

use RuntimeException;

/** No tenant is set: tenant-scoped data must never be touched (fail closed). */
final class TenantNotResolved extends RuntimeException
{
    public function __construct(string $message = 'No tenant is resolved for this operation.')
    {
        parent::__construct($message);
    }
}
