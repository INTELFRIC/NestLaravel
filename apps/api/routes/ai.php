<?php

use App\Mcp\Servers\PlatformServer;
use Laravel\Mcp\Facades\Mcp;

$webMiddleware = ['throttle:mcp'];

if (filter_var(env('MCP_WEB_AUTH', true), FILTER_VALIDATE_BOOLEAN)) {
    $webMiddleware[] = 'auth:sanctum';
}

Mcp::web('/mcp/platform', PlatformServer::class)
    ->middleware($webMiddleware);

Mcp::local('platform', PlatformServer::class);
