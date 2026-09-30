# Platform (Nest-Laravel)

This application is a Laravel 13 modular monolith. Do not generate a flat Controllers/Services tree.

- Modules live in `app/Modules/{Name}` with Domain / Application / Infrastructure / Presentation
- `apps/api` (this app) is the API gateway; portals never call microservices directly
- Scaffold with `php artisan make:module`, `make:action`, `make:dto`
- MCP server: `php artisan mcp:start platform`
- Agent skills: `.ai/skills/` and the monorepo `.cursor/skills/`

See `docs/architecture.md` and `docs/mcp.md` at the repository root.
