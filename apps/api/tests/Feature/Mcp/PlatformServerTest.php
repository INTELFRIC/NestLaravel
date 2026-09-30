<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Prompts\CreateModulePrompt;
use App\Mcp\Resources\ArchitectureGuidelinesResource;
use App\Mcp\Servers\PlatformServer;
use App\Mcp\Tools\DescribeModuleTool;
use App\Mcp\Tools\GetHealthTool;
use App\Mcp\Tools\ListGatewayServicesTool;
use App\Mcp\Tools\ListModulesTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_modules_includes_auth_and_users(): void
    {
        $response = PlatformServer::tool(ListModulesTool::class);

        $response
            ->assertOk()
            ->assertSee('Auth')
            ->assertSee('Users')
            ->assertSee('Notifications');
    }

    public function test_describe_module_returns_auth_layers(): void
    {
        $response = PlatformServer::tool(DescribeModuleTool::class, [
            'module' => 'Auth',
        ]);

        $response
            ->assertOk()
            ->assertSee('Domain')
            ->assertSee('Application')
            ->assertSee('AuthController');
    }

    public function test_describe_module_rejects_unknown_module(): void
    {
        $response = PlatformServer::tool(DescribeModuleTool::class, [
            'module' => 'DoesNotExist',
        ]);

        $response->assertHasErrors();
    }

    public function test_get_health_returns_status_payload(): void
    {
        $response = PlatformServer::tool(GetHealthTool::class);

        $response
            ->assertOk()
            ->assertSee('status')
            ->assertSee('checks');
    }

    public function test_list_gateway_services_includes_orders(): void
    {
        $response = PlatformServer::tool(ListGatewayServicesTool::class);

        $response
            ->assertOk()
            ->assertSee('orders')
            ->assertSee('/api/v1/orders');
    }

    public function test_architecture_resource_is_readable(): void
    {
        $response = PlatformServer::resource(ArchitectureGuidelinesResource::class);

        $response
            ->assertOk()
            ->assertSee('Domain')
            ->assertSee('Presentation');
    }

    public function test_create_module_prompt_mentions_artisan(): void
    {
        $response = PlatformServer::prompt(CreateModulePrompt::class, [
            'name' => 'Fleet',
        ]);

        $response
            ->assertOk()
            ->assertSee('make:module Fleet')
            ->assertSee('FleetServiceProvider');
    }

    public function test_http_mcp_requires_sanctum_when_auth_enabled(): void
    {
        $this->postJson('/mcp/platform', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertUnauthorized();
    }

    public function test_http_mcp_accepts_sanctum_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mcp')->plainTextToken;

        $this->withToken($token)
            ->postJson('/mcp/platform', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => new \stdClass,
                    'clientInfo' => [
                        'name' => 'phpunit',
                        'version' => '1.0.0',
                    ],
                ],
            ])
            ->assertOk();
    }
}
