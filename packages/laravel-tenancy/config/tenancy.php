<?php

return [
    // Column that owns a row. Never mass-assignable; stamped by BelongsToTenant.
    'column' => 'tenant_id',

    // Request attribute holding the gateway-signed tenant (set by VerifyGatewaySignature).
    'request_attribute' => 'gateway_tenant_id',
];
