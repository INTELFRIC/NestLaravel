<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Guides an agent to add a NestJS-style Next.js UI portal that talks only to the API gateway.')]
class CreatePortalPrompt extends Prompt
{
    /**
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ], [
            'name.required' => 'Provide a kebab-case portal name such as partner-portal.',
        ]);

        $name = $validated['name'];

        return [
            Response::text(<<<MARKDOWN
You are adding a UI portal to this monorepo.

Rules:
- Portals live in `apps/{name}` as Next.js apps
- They consume `@platform/api-client` and talk ONLY to `apps/api` (the gateway)
- Never call extracted microservice URLs from the browser
- Copy `src/lib/api.ts` and the login page pattern from `apps/customer-portal`
- Add the workspace, Nx `project.json`, a unique port, and the origin to `CORS_ALLOWED_ORIGINS`

Steps:
1. `npx create-next-app@latest apps/{$name} --ts --eslint --app --src-dir --use-npm`
2. Add `"{$name}"` to root `package.json` workspaces
3. Depend on `@platform/api-client`
4. Auth: `POST /api/auth/login` → store `data.token` → `GET /api/auth/me` with Bearer token
5. Add origin to `apps/api/.env` `CORS_ALLOWED_ORIGINS`

Read the `make-portal` agent skill and `docs/portals.md`.
MARKDOWN)->asAssistant(),
            Response::text("Create the {$name} portal following the platform portal conventions."),
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
                description: 'Kebab-case portal directory name, e.g. partner-portal.',
                required: true,
            ),
        ];
    }
}
