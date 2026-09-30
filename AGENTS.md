# Agent instructions

This is a **Laravel 13 enterprise platform** (modular monolith + Next.js portals). `apps/api` is the API gateway. Portals never call microservices directly.

## Always

1. Read `.cursor/skills/platform-architecture/SKILL.md` before changing domain structure.
2. Put business logic in `apps/api/app/Modules/{Name}` (Domain → Application → Infrastructure → Presentation). Do not use a flat Controllers/Services tree.
3. Generate with Artisan from `apps/api`: `make:module`, `make:action`, `make:dto`, `make:domain-event`, `make:consumer`.
4. Controllers call Actions only. Repository contracts live in Domain.
5. Cross-module access: contracts or events — never another module's Eloquent models.

## Skills (on demand)

| Skill | When |
|-------|------|
| `make-module` | New business module / vertical slice |
| `make-portal` | New Next.js UI portal |
| `extract-microservice` | Split a module into a service behind the gateway |
| `platform-mcp` | MCP tools, resources, prompts, or agent skills |

## MCP

Local server handle `platform` (`php artisan mcp:start platform` in `apps/api`). Tools: `list-modules`, `describe-module`, `get-health`, `list-gateway-services`. Docs: [docs/mcp.md](docs/mcp.md).

## Docs

[architecture](docs/architecture.md) · [modules](docs/modules.md) · [gateway](docs/gateway.md) · [portals](docs/portals.md) · [microservices](docs/microservices.md) · [development](docs/development.md)


<!-- nx configuration start-->
<!-- Leave the start & end comments to automatically receive updates. -->

## General Guidelines for working with Nx

- For navigating/exploring the workspace, invoke the `nx-workspace` skill first - it has patterns for querying projects, targets, and dependencies
- When running tasks (for example build, lint, test, e2e, etc.), always prefer running the task through `nx` (i.e. `nx run`, `nx run-many`, `nx affected`) instead of using the underlying tooling directly
- Prefix nx commands with the workspace's package manager (e.g., `pnpm nx build`, `npm exec nx test`) - avoids using globally installed CLI
- You have access to the Nx MCP server and its tools, use them to help the user
- For Nx plugin best practices, check `node_modules/@nx/<plugin>/PLUGIN.md`. Not all plugins have this file - proceed without it if unavailable.
- NEVER guess CLI flags - always check nx_docs or `--help` first when unsure

## Scaffolding & Generators

- For scaffolding tasks (creating apps, libs, project structure, setup), ALWAYS invoke the `nx-generate` skill FIRST before exploring or calling MCP tools

## When to use nx_docs

- USE for: advanced config options, unfamiliar flags, migration guides, plugin configuration, edge cases
- DON'T USE for: basic generator syntax (`nx g @nx/react:app`), standard commands, things you already know
- The `nx-generate` skill handles generator discovery internally - don't call nx_docs just to look up generator syntax


<!-- nx configuration end-->