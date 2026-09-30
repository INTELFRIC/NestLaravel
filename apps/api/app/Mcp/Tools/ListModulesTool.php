<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\ModuleCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lists business modules under app/Modules with their layers, provider, and route files.')]
#[IsReadOnly]
#[IsIdempotent]
class ListModulesTool extends Tool
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function handle(Request $request): ResponseFactory
    {
        $modules = $this->catalog->all();

        return Response::structured([
            'count' => count($modules),
            'modules' => $modules,
            'hint' => 'Scaffold a new module with: php artisan make:module {Name}',
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
