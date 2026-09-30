<?php

/**
 * SERVICE-TO-SERVICE trust configuration.
 * INTERNAL_SERVICE_SECRET must equal the gateway's {NAME}_SERVICE_SECRET for this service.
 */
return [
    'secret' => env('INTERNAL_SERVICE_SECRET', ''),
    'signature_ttl' => (int) env('INTERNAL_SIGNATURE_TTL', 60),
];
