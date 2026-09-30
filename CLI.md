# CLI reference

```bash
npx nestlaravel <command> [options]        # no install needed
npm i -D nestlaravel                       # or per-project (create adds it for you)
```

| Command | What it does |
|---------|--------------|
| `create <name>` | New workspace. `--db sqlite\|pgsql\|mysql`, `--with-portals`, `--migrate`, `--skip-install`, `--skip-git`, `--skip-validate`, `-y` |
| `generate service <name>` | New Laravel microservice (see [MICROSERVICES.md](MICROSERVICES.md)). `--port N`, `--force`, `--skip-install` |
| `generate kafka-event <type>` | Domain event class (+ `--consumer` handler). `--service <svc>` (use `api` for the gateway) |
| `generate kafka-topic <name>` | Registers a topic in `config/kafka.php` and `.env.example`. `--service <svc>`, `--create` (creates it + `.dlq` on the dev broker) |
| `add tenancy` | Installs the multi-tenancy package ([MULTI-TENANCY.md](MULTI-TENANCY.md)). `--service a,b` |
| `dev` | Starts Kafka/Postgres/Redis (Docker) then serves all apps via Nx. `--no-infra`, `--docker` (full containerised stack) |
| `test` / `lint` | `nx run-many -t test\|lint`. `--affected`, `--project <name>`, extra Nx args after `--` |
| `build` | Production Docker images via Nx. `--tag 1.2.3`, `--registry ghcr.io/acme` |
| `doctor` | Verifies Node, npm, PHP (+ extensions), Composer, Git, Docker, php-rdkafka |
| `update` | Safe upgrade of an existing workspace. `--dry-run`, `--adopt`, `--allow-major`, `-y` |

Aliases: `new`=`create`, `g`/`gen`=`generate`, `serve`=`dev`, `upgrade`=`update`.
Equivalent Nx usage works everywhere: `npx nx test orders-service`, `npx nx serve api`, `npx nx graph`.

## Examples

```bash
npx nestlaravel create shop --db pgsql --migrate
cd shop
npx nestlaravel generate service orders
npx nestlaravel generate service payments --port 8055
npx nestlaravel generate kafka-topic order-events --service orders --create
npx nestlaravel generate kafka-event order.created --service orders --consumer
npx nestlaravel test --affected
IMAGE_TAG=1.0.0 npx nestlaravel build --registry ghcr.io/acme
```

## Exit codes and safety

* Non-zero on any failed step; errors name the command that failed.
* Names are validated (`^[a-z][a-z0-9-]*$`), never interpolated into a shell; Windows `cmd.exe` arguments are quoted
  and refused if they contain `%` or newlines.
* Generators refuse to overwrite without `--force`; `update` backs up everything it changes and never edits user code
  ([UPGRADING.md](UPGRADING.md)).

## Environment variables

| Variable | Effect |
|----------|--------|
| `NO_COLOR` | disable colours |
| `NESTLARAVEL_DEBUG=1` | print stack traces |
| `IMAGE_TAG`, `REGISTRY` | image name for `build` (or `--tag/--registry`) |
| `NESTLARAVEL_CLI_SPEC` | (testing) devDependency spec written into new projects, e.g. `file:./nestlaravel.tgz` |
