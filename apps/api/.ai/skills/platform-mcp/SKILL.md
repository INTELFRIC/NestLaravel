---
name: platform-mcp
description: Adds MCP servers, tools, resources, prompts, and agent skills on this platform. Use when the user mentions MCP, Model Context Protocol, laravel/mcp, make:mcp-tool, agent skills, SKILL.md, or make:agent-skill.
---

# Platform MCP and agent skills

## MCP (runtime tools for agents)

Package: `laravel/mcp`. Server: `App\Mcp\Servers\PlatformServer`.

| Transport | Registration | How to run |
|-----------|--------------|------------|
| Local stdio | `Mcp::local('platform', …)` in `routes/ai.php` | `php artisan mcp:start platform` |
| HTTP | `Mcp::web('/mcp/platform', …)` | `POST /mcp/platform` (Sanctum when `MCP_WEB_AUTH=true`) |

### Add a tool

```bash
cd apps/api
php artisan make:mcp-tool ListInvoicesTool
```

Register the class on `PlatformServer::$tools`. Keep tools read-only unless mutation is explicit. Inject Laravel contracts (same DI rules as the rest of the app).

Companion generators: `make:mcp-resource`, `make:mcp-prompt`, `make:mcp-server`.

Test:

```php
$response = PlatformServer::tool(ListInvoicesTool::class, ['status' => 'open']);
$response->assertOk();
```

Inspector: `php artisan mcp:inspector platform`

## Agent skills (on-demand instructions)

| Location | Consumer |
|----------|----------|
| `.cursor/skills/{name}/SKILL.md` | Cursor |
| `apps/api/.ai/skills/{name}/SKILL.md` | Laravel Boost |

```bash
php artisan make:agent-skill creating-invoices --description="How to create invoices in the Orders module"
```

SKILL.md requirements: YAML `name` + `description` (what + when, third person), body under 500 lines, progressive disclosure to sibling `.md` files.

Do not create skills in `~/.cursor/skills-cursor/`.
