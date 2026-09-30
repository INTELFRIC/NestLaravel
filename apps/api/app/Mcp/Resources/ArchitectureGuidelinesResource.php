<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Layered architecture rules for this Laravel enterprise platform (Domain → Application → Infrastructure → Presentation).')]
#[Uri('platform://architecture')]
#[MimeType('text/markdown')]
class ArchitectureGuidelinesResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text(<<<'MARKDOWN'
# Platform architecture

This starter is a Laravel 13 modular monolith that can evolve into microservices without rewriting domain logic.

## Request flow

HTTP / API / Kafka consumer → Presentation → Application → Domain → Infrastructure

## Layout (`apps/api/app`)

- `Core/` — shared contracts, DTOs, exceptions, value objects
- `Modules/{Name}/` — one business capability
- `Infrastructure/` — Redis, Kafka, outbox, gateway
- `Messaging/` — domain events, serializers, consumers
- `Security/` — authn, authz, rate limits
- `Observability/` — logging, tracing, health
- `Mcp/` — MCP servers, tools, resources, prompts for AI agents

Each module:

```
Modules/{Name}/
├── Domain/           Entities, ValueObjects, Events, Exceptions, Contracts
├── Application/      Actions, Commands, Queries, DTOs, Services
├── Infrastructure/   Persistence, Repositories, Consumers, Providers
└── Presentation/     Controllers, Requests, Resources, Routes
```

## Dependency rules

| Layer | May depend on | Must not depend on |
|-------|---------------|--------------------|
| Domain | PHP + Core types | Eloquent, Redis, Kafka, HTTP, Facades |
| Application | Domain + Core contracts | Controllers, persistence internals |
| Infrastructure | Domain contracts + Laravel | Presentation |
| Presentation | Application Actions + DTOs | Domain persistence internals |

## Communication

Allowed: another module's public contract, application service, or domain/integration events.
Forbidden: another module's Eloquent models, private repositories, or casual shared tables.

## Evolution

Laravel monolith → modular monolith (default) → Redis/queues → Kafka → extract modules into services behind `apps/api` (gateway).

Portals never call microservices directly. Register downstream apps in `config/gateway.php`.
MARKDOWN);
    }
}
