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

#[Description('Describes one business module: layers, provider, routes, and PHP files. Use after list-modules.')]
#[IsReadOnly]
#[IsIdempotent]
class DescribeModuleTool extends Tool
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'module' => ['required', 'string', 'max:80'],
        ], [
            'module.required' => 'Provide a module name such as Auth, Users, or Orders.',
        ]);

        $module = $this->catalog->find($validated['module']);

        if ($module === null) {
            return Response::error(
                "Module [{$validated['module']}] was not found under app/Modules. Available: "
                .implode(', ', $this->catalog->names())
            );
        }

        return Response::structured($module);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()
                ->description('Module name in StudlyCase, e.g. Auth, Users, Orders.')
                ->required(),
        ];
    }
}
