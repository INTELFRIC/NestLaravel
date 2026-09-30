<?php

namespace NestLaravel\Tenancy\Exceptions;

use RuntimeException;

/** An attempt to write to, or re-assign, another tenant's data. */
final class CrossTenantAccess extends RuntimeException
{
    public function __construct(string $message = 'Cross-tenant access denied.')
    {
        parent::__construct($message);
    }
}
