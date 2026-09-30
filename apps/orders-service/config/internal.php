<?php

/**
 * SERVICE-TO-SERVICE trust configuration.
 * INTERNAL_SERVICE_SECRET must equal the gateway's {NAME}_SERVICE_SECRET for this service.
 */
return [
    'secret' => env('INTERNAL_SERVICE_SECRET', ''),

    // Zero-downtime rotation: put the OLD secret here while the gateway is being rolled to the new one.
    'secret_previous' => env('INTERNAL_SERVICE_SECRET_PREVIOUS', ''),

    'signature_ttl' => (int) env('INTERNAL_SIGNATURE_TTL', 60),

    // Cache store holding single-use nonces. Must be shared by all replicas (Redis/database), not "array"/"file".
    'nonce_store' => env('INTERNAL_NONCE_STORE') ?: null,

    // true: refuse requests when the nonce store is down (replay protection cannot be guaranteed).
    'replay_protection_required' => (bool) env('INTERNAL_REPLAY_PROTECTION_REQUIRED', true),
];
