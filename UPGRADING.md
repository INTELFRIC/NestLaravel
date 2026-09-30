# Upgrading

```bash
npx nestlaravel update --dry-run     # 1. see the plan, change nothing
npx nestlaravel update               # 2. apply (asks for confirmation; -y in CI)
```

What `update` does, in order:

1. **Detects** the workspace version (`nestlaravel.json`) and the installed CLI version.
2. **Checks compatibility**: refuses to go backwards; refuses a major jump without `--allow-major`; verifies Node/PHP/Composer.
3. **Prints the plan**: migration steps still needed, framework files it would add/replace, files that need your review.
4. **Backs up** every file it will change to `.nestlaravel/backup/<timestamp>/` (plus `BEFORE.json`).
5. **Applies** idempotent migrations and syncs *managed* framework files.
6. **Updates dependencies**: bumps the `nestlaravel` devDependency and runs `npm install`; runs `composer audit` per app and tells you when to `composer update`.
7. **Runs the tests** (`nx run-many -t test`) and records the new version in `nestlaravel.json`.

### The rules it follows

* **User code is never modified.** Only *managed* files — gateway signing, the Kafka kit, Docker build files,
  service verification middleware — are eligible, and only while byte-identical to what a previous release wrote
  (hashes in `.nestlaravel/managed.json`).
* If you edited a managed file, `update` leaves it alone and writes the new version beside it as
  `<file>.nestlaravel-new` for you to merge; it tells you which ones.
* Migrations are idempotent; running `update` twice is safe. If a step fails, the recorded version is not changed
  and your originals are in the backup directory.

## Workspaces created before the CLI existed

```bash
cd your-existing-workspace
npx nestlaravel update --adopt --dry-run
npx nestlaravel update --adopt
```

`--adopt` writes `nestlaravel.json`, starts tracking managed files, and applies the 1.0.0 migration.

## 1.0.0 (first CLI release) — what changes for existing projects

| Change | Automated? | Action |
|--------|-----------|--------|
| Gateway config: per-service `secret`, `public=false`, `forward_auth=false` | ✅ patches `config/gateway.php` | review the diff |
| Services verify the gateway signature (`VerifyGatewaySignature`, `config/internal.php`) | ✅ copies files, patches the standard module provider | if your provider is customised you get a warning: add `VerifyGatewaySignature::class` to the route middleware |
| Matching secrets (`<NAME>_SERVICE_SECRET` ⇄ `INTERNAL_SERVICE_SECRET`) | ✅ generated when missing | restart gateway + services |
| `packages/laravel-kafka` added | ✅ | add `nestlaravel/kafka` (path repo) to a service's `composer.json` to use it |
| `APP_DEBUG=true` → `false` in `.env.example` files | ✅ | set explicitly in your real `.env` |
| Gateway code (`GatewayProxy`, `GatewaySigner`, Kafka producer/consumer/pipeline, outbox) | ⚠ unmodified files replaced; modified → `*.nestlaravel-new` | merge |
| **Self-registration role escalation** (`role` accepted verbatim) | ❌ reported only (user-owned code) | restrict to `Rule::in(config('auth.self_registration_roles'))` in `RegisterRequest` **and** the `RegisterUser` action — **do this before exposing the API** |
| Docker Compose: KRaft Kafka, no ZooKeeper/MinIO/Mailpit by default, required passwords, `127.0.0.1` binds | ❌ not overwritten | compare with `docker-compose*.yml` from a fresh `create`; MinIO/Mailpit are in your compose if you still need them; existing Postgres volumes are unaffected |
| `make:microservice` reserved names: `Users` is now allowed | – | none |
| Sanctum tokens now expire after 24 h | config default | set `SANCTUM_TOKEN_EXPIRATION` (minutes) or `null` to restore |
| `messaging:outbox-publish` marks rows only after broker confirmation | code | none; watch for slower batches |
| Event envelope gained `source` (and optional `tenant_id`) | additive | none |

### Breaking changes, summarised

1. Gateway → service calls require the HMAC headers. Direct calls to services (from scripts, Postman, other services)
   now fail with 401 — route them through the gateway.
2. The gateway no longer forwards the user's bearer token to services, and proxy routes require authentication
   (`public=true` per service to opt out).
3. `GET /api/gateway/services` needs authentication and no longer returns internal URLs.
4. `docker-compose.infra.yml` is now a standalone infra file (not an overlay); `docker-compose.yml` includes it.

## Nx upgrades

`nestlaravel update` does not migrate Nx itself. Use Nx's own tool and re-run the tests:

```bash
npx nx migrate latest && npm install && npx nx migrate --run-migrations
```

## Rolling back

Copy the files from `.nestlaravel/backup/<timestamp>/` back (paths mirror the workspace) and set the version in
`nestlaravel.json` back to the value in `BEFORE.json`; then `npm install nestlaravel@<old>`.
