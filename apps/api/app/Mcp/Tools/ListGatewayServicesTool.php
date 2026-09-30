<?php

namespace App\Mcp\Tools;

use App\Infrastructure\Gateway\ServiceDefinition;
use App\Infrastructure\Gateway\ServiceRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists API gateway downstream microservices from config/gateway.php (prefix, URL, enabled).')]
#[IsReadOnly]
#[IsIdempotent]
class ListGatewayServicesTool extends Tool
{
    public function __construct(private readonly ServiceRegistry $registry) {}

    public function handle(Request $request): ResponseFactory
    {
        $services = array_map(
            static fn (ServiceDefinition $service): array => [
                'name' => $service->name,
                'prefix' => $service->prefix,
                'base_url' => $service->baseUrl,
                'enabled' => $service->enabled,
                'timeout' => $service->timeout,
                'forward_auth' => $service->forwardAuth,
                'public_path' => '/api/v1/'.$service->prefix,
            ],
            array_values($this->registry->all()),
        );

        return Response::structured([
            'gateway_enabled' => (bool) config('gateway.enabled', true),
            'count' => count($services),
            'services' => $services,
            'hint' => 'Portals must call the gateway only. Enable a service in config/gateway.php or via GATEWAY_{NAME}_ENABLED.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
