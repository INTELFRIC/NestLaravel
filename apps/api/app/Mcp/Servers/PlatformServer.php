<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\CreateModulePrompt;
use App\Mcp\Prompts\CreatePortalPrompt;
use App\Mcp\Prompts\ExtractMicroservicePrompt;
use App\Mcp\Resources\ArchitectureGuidelinesResource;
use App\Mcp\Resources\ModuleConventionsResource;
use App\Mcp\Tools\DescribeModuleTool;
use App\Mcp\Tools\GetHealthTool;
use App\Mcp\Tools\ListGatewayServicesTool;
use App\Mcp\Tools\ListModulesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Platform')]
#[Version('1.0.0')]
#[Instructions('Laravel 13 enterprise platform MCP. Inspect modules, health, and the API gateway. Prefer make:module / make:action generators over a flat Controllers/Services tree. Portals talk only to apps/api.')]
class PlatformServer extends Server
{
    protected array $tools = [
        ListModulesTool::class,
        DescribeModuleTool::class,
        GetHealthTool::class,
        ListGatewayServicesTool::class,
    ];

    protected array $resources = [
        ArchitectureGuidelinesResource::class,
        ModuleConventionsResource::class,
    ];

    protected array $prompts = [
        CreateModulePrompt::class,
        CreatePortalPrompt::class,
        ExtractMicroservicePrompt::class,
    ];
}
