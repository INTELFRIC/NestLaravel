---
name: platform-architecture
description: Enforces this Laravel enterprise platform's layered modules, gateway, and portal conventions. Use when adding features, modules, services, or when the user mentions architecture, Domain, Application, Infrastructure, Presentation, or Nest-Laravel.
---

# Platform architecture

This repo is a **Laravel 13 modular monolith** plus Next.js portals. `apps/api` is the API gateway.

## Layers

```
HTTP / Kafka → Presentation → Application → Domain → Infrastructure
```

| Layer | Lives in | Must not |
|-------|----------|----------|
| Domain | `Modules/{Name}/Domain` | Eloquent, Redis, Kafka, HTTP, Facades |
| Application | `Modules/{Name}/Application` | Controllers, persistence internals |
| Infrastructure | `Modules/{Name}/Infrastructure` + `app/Infrastructure` | Presentation |
| Presentation | `Modules/{Name}/Presentation` | Domain persistence internals |

## Do

- One business capability = one module under `apps/api/app/Modules/{Name}`
- Controllers call Actions; Actions use repository **contracts**
- Cross-module: public contracts, application services, or events
- Portals call **only** `apps/api` (Sanctum). Never hit microservice URLs from the browser
- Generate with `php artisan make:module|action|dto|domain-event|consumer`

## Do not

- Put domain logic in a flat `Controllers/Services` tree
- Import another module's Eloquent models or private repositories
- Chain sync HTTP gateway → A → B → C for one request
- Enable Kafka/CQRS/event sourcing unless the problem needs it

## Read next

- [docs/architecture.md](../../../docs/architecture.md)
- Skills: `make-module`, `make-portal`, `extract-microservice`, `platform-mcp`
