# Architecture Overview

This project is a **Laravel 13 Enterprise Architecture Starter**. It supports a modular monolith today and can evolve into independently deployable microservices without rewriting domain logic.

## Goals

- Clear separation of concerns (Domain → Application → Infrastructure → Presentation)
- Business modules isolated behind contracts and events
- Infrastructure (Postgres, Redis, Kafka, queues) behind interfaces
- Horizontal scaling via workers and event-driven communication
- Practical patterns — advanced only when they earn their keep

## Layered flow

```text
HTTP / API / Kafka consumer
           ↓
   Presentation Layer   (Controllers, Requests, Resources, Routes)
           ↓
   Application Layer    (Actions, DTOs, Queries, Commands)
           ↓
   Domain Layer         (Entities, Value Objects, Contracts, Domain Events)
           ↓
   Infrastructure       (Eloquent, Redis, Kafka, external APIs)
```

## Top-level layout

```text
app/
├── Core/               Shared contracts, DTOs, exceptions, support
├── Modules/            Business capabilities (Auth, Users, Orders, …)
├── Infrastructure/     Cross-cutting adapters (DB, Redis, Kafka, Outbox, …)
├── Messaging/          Event contracts, abstract events, serializers
├── Security/           Authn, authz, policies, rate limiting
└── Observability/      Logging, metrics, tracing, health
```

Each module follows the same internal shape:

```text
Modules/{Name}/
├── Domain/
├── Application/
├── Infrastructure/
└── Presentation/
```

## Dependency rules

| Layer | May depend on | Must not depend on |
|-------|---------------|--------------------|
| Domain | PHP + Core value types | Eloquent, Redis, Kafka, HTTP, Facades |
| Application | Domain contracts, Core contracts | Controllers, frameworks details where avoidable |
| Infrastructure | Domain contracts, Laravel, drivers | Presentation |
| Presentation | Application Actions + DTOs | Domain persistence internals |

Cross-module access goes through **contracts**, **application services**, or **domain/integration events** — never another module’s Eloquent models or private repositories.

## Evolution path

```text
Laravel Monolith
    → Modular Monolith (default)
    → High-load app + Redis + queues
    → Event-driven with Kafka
    → Extract modules into microservices
```

Domain and Application code should remain portable across those stages.

## Related docs

- [Modules](modules.md)
- [Dependency injection](dependency-injection.md)
- [Events](events.md)
- [Microservices](microservices.md)
- [Development](development.md)
