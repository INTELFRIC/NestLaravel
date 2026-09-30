<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Guides an agent to extract a local module into a microservice behind the API gateway.')]
class ExtractMicroservicePrompt extends Prompt
{
    /**
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        $validated = $request->validate([
            'module' => ['required', 'string', 'max:80'],
        ], [
            'module.required' => 'Provide the module to extract, e.g. Orders.',
        ]);

        $module = $validated['module'];
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $module) ?? $module);

        return [
            Response::text(<<<MARKDOWN
You are extracting the {$module} module into an independent Laravel microservice.

Preconditions:
- Module owns its tables
- No shared Eloquent across modules
- Integration events are versioned
- Auth stays on the gateway; the service trusts forwarded Bearer tokens

Steps:
1. `cd apps/api && php artisan make:microservice {$module}` (creates `apps/{$kebab}-service` and registers a disabled gateway entry)
2. Keep Domain + Application portable; move them into that app
3. Give the service its own `.env`, database, workers, Kafka consumer group, health checks
4. Enable `GATEWAY_{$module}_ENABLED=true` in `apps/api/.env` when the service is running
5. Remove local routes for that prefix so the gateway proxy owns `/api/v1/{$kebab}/*`
6. Prefer Kafka for fan-out; use HTTP proxy only when the client needs a sync response
7. Do not chain gateway → A → B → C for one request

Use MCP `list-gateway-services` after registration. Read the `extract-microservice` skill and `docs/microservices.md`.
MARKDOWN)->asAssistant(),
            Response::text("Extract the {$module} module into a microservice behind the API gateway."),
        ];
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'module',
                description: 'StudlyCase module name to extract, e.g. Orders.',
                required: true,
            ),
        ];
    }
}
