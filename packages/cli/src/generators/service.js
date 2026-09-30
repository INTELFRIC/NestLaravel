import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { editFile, ensureDir, writeIfAbsent } from '../fsx.js';
import { run } from '../exec.js';
import { appKey, secret, setEnv } from '../secrets.js';
import { c, CliError, log } from '../ui.js';
import { readManifest, toEnvName, toStudly, validateName, writeManifest } from '../workspace.js';
import { managedMap, recordManaged } from '../managed.js';
import { templatesDir } from '../fsx.js';

/**
 * `nestlaravel generate service <name>`
 *
 * 1. Delegates scaffolding to the framework's own Artisan generator (make:microservice), which
 *    copies the service template, registers the gateway route and Nx project.
 * 2. Adds what the generator cannot: per-service secrets, .env, database isolation, Docker wiring,
 *    manifest entry, dependency install.
 */
export async function generateService(root, rawName, flags) {
  const name = validateName(rawName, { kind: 'service name' });
  const dir = `${name}-service`;
  const appDir = join(root, 'apps', dir);
  const gatewayDir = join(root, 'apps', 'api');
  const manifest = readManifest(root);

  if (existsSync(appDir) && !flags.force) throw new CliError(`apps/${dir} already exists (use --force to replace it).`);
  if (!existsSync(join(gatewayDir, 'vendor', 'autoload.php'))) {
    throw new CliError('Gateway dependencies are missing. Run: composer install --working-dir=apps/api');
  }

  const port = flags.port ? Number(flags.port) : undefined;
  if (port !== undefined && (!Number.isInteger(port) || port < 1024 || port > 65535)) {
    throw new CliError('--port must be an integer between 1024 and 65535.');
  }

  log.step(`Generating Laravel service ${c.cyan(dir)}`);
  const artisan = ['artisan', 'make:microservice', toStudly(name), '--no-interaction'];
  if (port) artisan.push(`--port=${port}`);
  if (flags.force) artisan.push('--force');
  await run('php', artisan, { cwd: gatewayDir, silent: true });

  const env = toEnvName(name);
  const servicePort = readPort(appDir) ?? port ?? 8001;
  const serviceSecret = secret(32);
  const dbPassword = secret(24);

  // Service .env (own APP_KEY, own signing secret, own database credentials).
  let svcEnv = readFileSync(join(appDir, '.env.example'), 'utf8');
  svcEnv = setEnv(svcEnv, 'APP_KEY', appKey());
  svcEnv = setEnv(svcEnv, 'INTERNAL_SERVICE_SECRET', serviceSecret);
  svcEnv = setEnv(svcEnv, 'APP_URL', `http://127.0.0.1:${servicePort}`);
  svcEnv = setEnv(svcEnv, 'DB_CONNECTION', manifest.database === 'sqlite' ? 'sqlite' : manifest.database);
  if (manifest.database !== 'sqlite') {
    svcEnv = setEnv(svcEnv, 'DB_HOST', '127.0.0.1');
    svcEnv = setEnv(svcEnv, 'DB_PORT', manifest.database === 'pgsql' ? '5432' : '3306');
    svcEnv = setEnv(svcEnv, 'DB_DATABASE', name.replace(/-/g, '_'));
    svcEnv = setEnv(svcEnv, 'DB_USERNAME', name.replace(/-/g, '_'));
    svcEnv = setEnv(svcEnv, 'DB_PASSWORD', dbPassword);
  }
  writeIfAbsent(join(appDir, '.env'), svcEnv, { overwrite: Boolean(flags.force) });
  if (manifest.database === 'sqlite') writeIfAbsent(join(appDir, 'database', 'database.sqlite'), '');

  // Gateway .env: same secret, proxy enabled.
  const gatewayEnvPath = join(gatewayDir, '.env');
  if (existsSync(gatewayEnvPath)) {
    editFile(gatewayEnvPath, (t) => {
      let out = setEnv(t, `${env}_SERVICE_SECRET`, serviceSecret);
      out = setEnv(out, `${env}_SERVICE_URL`, `http://127.0.0.1:${servicePort}`);
      out = setEnv(out, `GATEWAY_${env}_ENABLED`, 'true');
      return out;
    });
  } else {
    log.warn(`apps/api/.env not found — set ${env}_SERVICE_SECRET yourself (must equal the service's INTERNAL_SERVICE_SECRET).`);
  }

  // Root .env: secrets consumed by docker-compose.
  const rootEnvPath = join(root, '.env');
  if (existsSync(rootEnvPath)) {
    editFile(rootEnvPath, (t) => setEnv(setEnv(t, `${env}_SERVICE_SECRET`, serviceSecret), `${env}_DB_PASSWORD`, dbPassword));
  }

  wireDocker(root, { name, dir, env });
  if (manifest.database === 'pgsql') writePostgresInit(root, name);

  if (!flags['skip-install']) {
    log.step(`Installing PHP dependencies for ${dir}`);
    await run('composer', ['install', '--no-interaction', '--prefer-dist', '--no-progress'], { cwd: appDir });
  }

  try {
    recordManaged(root, managedMap(templatesDir(), [name]));
  } catch {
    /* templates unavailable (dev checkout) � update will treat the files as user-modified */
  }

  manifest.services = [...new Set([...(manifest.services ?? []), name])].sort();
  writeManifest(root, manifest);

  log.ok(`Service ${c.bold(dir)} created (port ${servicePort}, gateway route /api/v1/${name}/…)`);
  log.blank();
  log.info('What was generated:');
  log.info(`  apps/${dir}/            Laravel app (Domain/Application/Infrastructure/Presentation module)`);
  log.info('  project.json            Nx targets: serve, test, lint, migrate, build (Docker image)');
  log.info('  .env                    own APP_KEY, INTERNAL_SERVICE_SECRET (gateway HMAC), Kafka settings');
  log.info('  apps/api/config/gateway.php + apps/api/.env   gateway route, signing secret, proxy enabled');
  log.info('  docker-compose.yml      internal-only container (no published ports) + outbox publisher');
  log.blank();
  log.info('Next:');
  log.cmd(`npx nestlaravel generate kafka-event ${name.replace(/-/g, '_')}.created --service ${name}`);
  log.cmd('npx nestlaravel dev');
}

function readPort(appDir) {
  try {
    const json = JSON.parse(readFileSync(join(appDir, 'project.json'), 'utf8'));
    const m = json.targets?.serve?.options?.command?.match(/--port=(\d+)/);
    return m ? Number(m[1]) : null;
  } catch {
    return null;
  }
}

/** Insert the service container (+ outbox publisher) at the marker in docker-compose.yml. */
function wireDocker(root, { name, dir, env }) {
  const compose = join(root, 'docker-compose.yml');
  if (!existsSync(compose)) return;

  const marker = '# @nestlaravel:services';
  const already = readFileSync(compose, 'utf8').includes(`  ${dir}:`);
  if (already) return;

  const block = `  ${dir}:
    <<: *laravel-app
    image: \${REGISTRY:-nestlaravel}/${dir}:\${IMAGE_TAG:-dev}
    build:
      context: .
      dockerfile: infrastructure/docker/laravel.Dockerfile
      args:
        APP_DIR: apps/${dir}
    env_file:
      - path: ./apps/${dir}/.env
        required: false
    environment:
      <<: *laravel-env
      APP_NAME: ${dir}
      DB_DATABASE: ${name.replace(/-/g, '_')}
      DB_USERNAME: ${name.replace(/-/g, '_')}
      DB_PASSWORD: \${${env}_DB_PASSWORD:?Set ${env}_DB_PASSWORD in .env}
      INTERNAL_SERVICE_SECRET: \${${env}_SERVICE_SECRET:?Set ${env}_SERVICE_SECRET in .env}
      KAFKA_CLIENT_ID: ${dir}
      KAFKA_GROUP_ID: ${dir}
      KAFKA_TOPIC_DEFAULT: ${name}.events
    # INTERNAL: intentionally no "ports:" — only the gateway may reach this service.

  ${dir}-outbox:
    <<: *laravel-app
    image: \${REGISTRY:-nestlaravel}/${dir}:\${IMAGE_TAG:-dev}
    build:
      context: .
      dockerfile: infrastructure/docker/laravel.Dockerfile
      args:
        APP_DIR: apps/${dir}
    command: ["php", "artisan", "messaging:outbox-publish", "--daemon"]
    env_file:
      - path: ./apps/${dir}/.env
        required: false
    environment:
      <<: *laravel-env
      APP_NAME: ${dir}
      DB_DATABASE: ${name.replace(/-/g, '_')}
      DB_USERNAME: ${name.replace(/-/g, '_')}
      DB_PASSWORD: \${${env}_DB_PASSWORD:?Set ${env}_DB_PASSWORD in .env}
      KAFKA_CLIENT_ID: ${dir}-outbox
      KAFKA_TOPIC_DEFAULT: ${name}.events
      SKIP_OPTIMIZE: "1"
    healthcheck:
      disable: true

`;

  editFile(compose, (text) => text.replace(`  ${marker}`, `${block}  ${marker}`));

  // Gateway container needs the same signing secret + service URL inside Docker.
  editFile(compose, (text) =>
    text.replace(
      '      # @nestlaravel:gateway-env',
      `      ${env}_SERVICE_URL: http://${dir}:80\n      ${env}_SERVICE_SECRET: \${${env}_SERVICE_SECRET:?Set ${env}_SERVICE_SECRET in .env}\n      GATEWAY_${env}_ENABLED: "true"\n      # @nestlaravel:gateway-env`,
    ),
  );
}

/** Database-per-service: a dedicated role + database created on first Postgres start. */
function writePostgresInit(root, name) {
  const dir = join(root, 'infrastructure', 'postgres', 'initdb');
  ensureDir(dir);
  const ident = name.replace(/-/g, '_');
  const file = join(dir, `20-${ident}.sh`);
  const script = `#!/bin/sh
# Creates the isolated database + role for the "${name}" service (runs once, on first init).
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE ROLE ${ident} LOGIN PASSWORD '$${ident.toUpperCase()}_DB_PASSWORD';
CREATE DATABASE ${ident} OWNER ${ident};
REVOKE ALL ON DATABASE ${ident} FROM PUBLIC;
SQL
`;
  writeIfAbsent(file, script);

  // Mount the init dir + pass the password into the postgres container.
  const infra = join(root, 'docker-compose.infra.yml');
  if (!existsSync(infra)) return;
  editFile(infra, (text) => {
    let out = text;
    if (!out.includes('/docker-entrypoint-initdb.d')) {
      out = out.replace(
        '      - postgres_data:/var/lib/postgresql/data\n',
        '      - postgres_data:/var/lib/postgresql/data\n      - ./infrastructure/postgres/initdb:/docker-entrypoint-initdb.d:ro\n',
      );
    }
    const envLine = `      ${ident.toUpperCase()}_DB_PASSWORD: \${${toEnvName(name)}_DB_PASSWORD:-}`;
    if (!out.includes(envLine)) {
      out = out.replace('      POSTGRES_PASSWORD:', `${envLine}\n      POSTGRES_PASSWORD:`);
    }
    return out;
  });
}
