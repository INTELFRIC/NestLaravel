# MCP and agent skills

This starter ships a **Model Context Protocol** server (Laravel MCP) and **Agent Skills** so coding agents follow the same architecture as `make:module`.

## What you get

| Piece | Role |
|-------|------|
| `laravel/mcp` | MCP runtime in `apps/api` |
| `PlatformServer` | Tools, resources, and prompts for this platform |
| `.cursor/skills/` | Cursor project skills |
| `apps/api/.ai/skills/` | Laravel Boost custom skills (same content) |
| `.mcp.json` | Local MCP client config (Cursor, Claude Code, Codex, …) |

## Local MCP (editors)

From the **repository root**, `.mcp.json` starts:

```bash
php artisan mcp:start platform
```

working directory `apps/api`.

Enable the `platform` server in your editor's MCP settings. Inspector:

```bash
cd apps/api
php artisan mcp:inspector platform
```

### Tools

| Tool | Purpose |
|------|---------|
| `list-modules` | Business modules under `app/Modules` |
| `describe-module` | One module's layers and PHP files |
| `get-health` | App / DB / Redis / Kafka health |
| `list-gateway-services` | Downstream services in `config/gateway.php` |

### Resources

- `platform://architecture`
- `platform://module-conventions`

### Prompts

- `create-module` (argument: `name`)
- `create-portal` (argument: `name`)
- `extract-microservice` (argument: `module`)

## HTTP MCP (remote agents)

```http
POST /mcp/platform
Authorization: Bearer {sanctum-token}   # when MCP_WEB_AUTH=true
```

| Env | Meaning |
|-----|---------|
| `MCP_WEB_AUTH=false` | Open HTTP MCP (local / inspector). Default in `.env.example` |
| `MCP_WEB_AUTH=true` | Require Sanctum (production). Default when the env var is omitted |

Rate limit: `throttle:mcp` (60/min). CORS includes `mcp/*`.

## Add an MCP tool

```bash
cd apps/api
php artisan make:mcp-tool ListInvoicesTool
```

Register it on `App\Mcp\Servers\PlatformServer::$tools`. Prefer read-only tools; inject contracts, not other modules' Eloquent models.

Also: `make:mcp-resource`, `make:mcp-prompt`, `make:mcp-server`.

Test:

```php
use App\Mcp\Servers\PlatformServer;
use App\Mcp\Tools\ListModulesTool;

$response = PlatformServer::tool(ListModulesTool::class);
$response->assertOk();
```

## Add an agent skill

```bash
cd apps/api
php artisan make:agent-skill creating-invoices --description="How to create invoices in the Orders module"
```

Writes:

- `.cursor/skills/creating-invoices/SKILL.md` (Cursor)
- `apps/api/.ai/skills/creating-invoices/SKILL.md` (Boost)

Keep `SKILL.md` under 500 lines. Put long reference material in sibling files.

Shipped skills: `platform-architecture`, `make-module`, `make-portal`, `extract-microservice`, `platform-mcp`.

## Optional: Laravel Boost

Boost (`composer require laravel/boost --dev` then `php artisan boost:install`) adds a **development** MCP (docs search, schema, logs). It is separate from `PlatformServer`. Custom skills in `.ai/skills/` are picked up on `boost:update`.

## Related

- [Architecture](architecture.md)
- [Modules](modules.md)
- [Development](development.md)
- [Gateway](gateway.md)
- [Portals](portals.md)
