# Contributing

Thanks for helping! This repository is both the framework source and its reference workspace.

## Setup

```bash
npm install
for d in apps/api apps/orders-service packages/laravel-kafka packages/laravel-tenancy; do (cd $d && composer install); done
```

## Test everything before a PR

```bash
(cd apps/api && php vendor/bin/pint --test && php vendor/bin/phpunit)
(cd apps/orders-service && php vendor/bin/pint --test && php vendor/bin/phpunit)   # also payments/notifications
(cd packages/laravel-kafka && php vendor/bin/phpunit)
(cd packages/laravel-tenancy && php vendor/bin/phpunit)
(cd packages/cli && npm test && npm run build:templates && npm run verify:package)
```

Changing `apps/api`, `apps/orders-service`, `packages/laravel-kafka`, `infrastructure/` or `docker-compose*.yml`? They
are **templates**: `packages/cli/scripts/build-templates.mjs` copies them into the npm package. Always run the
clean-install test (`scripts/e2e-clean-install.mjs`, see below) when touching them.

## Rules

* Business logic in `Modules/<Name>/{Domain,Application,Infrastructure,Presentation}`; controllers call Actions only;
  cross-module access via contracts/events (see AGENTS.md).
* No secrets, tokens or real `.env` in commits, fixtures or docs. Templates are secret-scanned; the build fails on hits.
* Security-relevant changes need a test that fails without the fix (see `GatewaySecurityTest`, `CrossTenantAccessTest`).
* Public behaviour (CLI flags, generated structure, env names, event fields) is API: additive changes only in minor
  releases; breaking changes need a migration in `packages/cli/src/migrations` + an `UPGRADING.md` entry.
* Conventional commits (`feat:`, `fix:`, `security:`, `docs:`) — they feed the changelog.

## Releasing

Semantic Versioning. Update `CHANGELOG.md`, then follow [DEPLOYMENT.md](DEPLOYMENT.md#publishing-nestlaravel-itself)
or push a `v*` tag / run the *Release* workflow.

## End-to-end clean-install test

```bash
node scripts/e2e-clean-install.mjs        # packs the CLI, installs it in a temp dir, creates a project,
                                          # generates a service + Kafka artefacts, runs tests
```
