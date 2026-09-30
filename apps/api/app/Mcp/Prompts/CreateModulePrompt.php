<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Guides an agent to scaffold a Domain/Application/Infrastructure/Presentation business module.')]
class CreateModulePrompt extends Prompt
{
    /**
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ], [
            'name.required' => 'Provide a StudlyCase module name such as Orders or Fleet.',
        ]);

        $name = $validated['name'];

        return [
            Response::text(<<<MARKDOWN
You are implementing a business module on this Laravel enterprise platform.

Rules:
- Vertical slice: Domain / Application / Infrastructure / Presentation under `app/Modules/{$name}`
- Domain must not use Eloquent, Redis, Kafka, HTTP, or Facades
- Controllers call Actions only; Actions use repository contracts
- Cross-module access via contracts or events only
- Register the module provider in `bootstrap/providers.php`
- Portals talk only to the API gateway (`apps/api`), never to a microservice URL

Commands (from `apps/api`):
1. `php artisan make:module {$name}`
2. Register `App\\Modules\\{$name}\\Infrastructure\\Providers\\{$name}ServiceProvider`
3. `php artisan make:dto Create{$name}Data --module={$name}`
4. `php artisan make:action Create{$name} --module={$name}`
5. `php artisan make:domain-event {$name}Created --module={$name}`

Read the `make-module` and `platform-architecture` agent skills before writing code.
Use MCP tools `list-modules` and `describe-module` to inspect existing modules as examples.
MARKDOWN)->asAssistant(),
            Response::text("Scaffold and implement the {$name} module following the platform conventions above."),
        ];
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'name',
                description: 'StudlyCase module name, e.g. Orders or Fleet.',
                required: true,
            ),
        ];
    }
}
