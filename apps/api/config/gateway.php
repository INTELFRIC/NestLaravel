<?php

/**
 * API Gateway service registry.
 *
 * apps/api is the single public entry point for portals and external clients.
 * Local modules (Auth, Users, …) are handled in-process.
 * Extracted microservices are reached by proxying matching URL prefixes.
 */
return [

    'enabled' => (bool) env('GATEWAY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Downstream microservices
    |--------------------------------------------------------------------------
    |
    | key     = logical service name
    | prefix  = public path under /api/v1/{prefix}/...
    | base_url = internal service URL (Docker DNS / k8s service)
    | enabled = when false, requests fall through (404 unless a local module owns the route)
    |
    */
    'services' => [

        'orders' => [
            'enabled' => (bool) env('GATEWAY_ORDERS_ENABLED', false),
            'prefix' => 'orders',
            'base_url' => env('ORDERS_SERVICE_URL', 'http://127.0.0.1:8001'),
            'timeout' => (float) env('GATEWAY_ORDERS_TIMEOUT', 10),
            // Forward Authorization + correlation headers
            // HMAC secret shared ONLY with this service (never reuse across services).
            'secret' => env('ORDERS_SERVICE_SECRET', ''),
            // Set true only for intentionally anonymous downstream routes.
            'public' => false,
            'forward_auth' => false,
        ],

        'payments' => [
            'enabled' => (bool) env('GATEWAY_PAYMENTS_ENABLED', false),
            'prefix' => 'payments',
            'base_url' => env('PAYMENTS_SERVICE_URL', 'http://127.0.0.1:8002'),
            'timeout' => (float) env('GATEWAY_PAYMENTS_TIMEOUT', 10),
            // HMAC secret shared ONLY with this service (never reuse across services).
            'secret' => env('PAYMENTS_SERVICE_SECRET', ''),
            // Set true only for intentionally anonymous downstream routes.
            'public' => false,
            'forward_auth' => false,
        ],

        'notifications' => [
            'enabled' => (bool) env('GATEWAY_NOTIFICATIONS_ENABLED', false),
            'prefix' => 'notifications',
            'base_url' => env('NOTIFICATIONS_SERVICE_URL', 'http://127.0.0.1:8003'),
            'timeout' => (float) env('GATEWAY_NOTIFICATIONS_TIMEOUT', 10),
            // HMAC secret shared ONLY with this service (never reuse across services).
            'secret' => env('NOTIFICATIONS_SERVICE_SECRET', ''),
            // Set true only for intentionally anonymous downstream routes.
            'public' => false,
            'forward_auth' => false,
        ],

    ],

    'default_timeout' => (float) env('GATEWAY_DEFAULT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Resilience (applies to every service; override per service with the same keys)
    |--------------------------------------------------------------------------
    |
    | connect_timeout / timeout  bound every attempt: nothing ever waits forever
    | retries                    extra attempts for TRANSIENT failures of SAFE requests only (GET/HEAD/OPTIONS, or
    |                            requests carrying an Idempotency-Key header, or when retry_unsafe is true)
    | breaker                    shared (cache-backed) circuit breaker: `threshold` failures within `window` seconds
    |                            open the circuit for `open` seconds; then one probe request decides
    |
    | Circuit/rate-limit/nonce state lives in the CACHE: use a shared store (redis) when running >1 gateway replica.
    */
    'resilience' => [
        'connect_timeout' => (float) env('GATEWAY_CONNECT_TIMEOUT', 2),
        'retries' => (int) env('GATEWAY_RETRIES', 2),
        'retry_base_ms' => (int) env('GATEWAY_RETRY_BASE_MS', 100),
        'retry_max_ms' => (int) env('GATEWAY_RETRY_MAX_MS', 2000),
        'retry_unsafe' => (bool) env('GATEWAY_RETRY_UNSAFE', false),
        'budget_ms' => (int) env('GATEWAY_RETRY_BUDGET_MS', 8000),
        'breaker' => [
            'threshold' => (int) env('GATEWAY_BREAKER_THRESHOLD', 5),
            'window' => (int) env('GATEWAY_BREAKER_WINDOW', 30),
            'open' => (int) env('GATEWAY_BREAKER_OPEN', 20),
        ],
        'cache_store' => env('GATEWAY_STATE_CACHE_STORE'),
    ],

    /*
    | Headers always forwarded to downstream services.
    */
    'forward_headers' => [
        'Accept',
        'Content-Type',
        'X-Request-ID',
        'X-Correlation-ID',
        // Lets clients opt a POST into safe retries: the same key => the same business operation.
        'Idempotency-Key',
    ],
];
