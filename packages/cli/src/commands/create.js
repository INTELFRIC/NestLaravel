import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { connect } from 'node:net';
import { join, resolve } from 'node:path';
import { parseArgs } from '../args.js';
import { checkRequirements, printRequirements } from '../doctor.js';
import { run, capture, nx } from '../exec.js';
import { copyTree, ensureDir, isEmptyDir, render, templatesDir, writeIfAbsent } from '../fsx.js';
import { appKey, secret, setEnv } from '../secrets.js';
import { c, CliError, log } from '../ui.js';
import { FRAMEWORK_VERSION, MANIFEST, RUNTIME } from '../versions.js';
import { validateName, writeManifest } from '../workspace.js';
import { managedMap, recordManaged } from '../managed.js';

const DB_CHOICES = ['sqlite', 'pgsql', 'mysql'];

/** True when something accepts TCP connections on host:port within the timeout. */
function canConnect(host, port, timeout = 2000) {
  return new Promise((done) => {
    const socket = connect({ host, port });
    const finish = (ok) => { socket.destroy(); done(ok); };
    socket.setTimeout(timeout, () => finish(false));
    socket.once('connect', () => finish(true));
    socket.once('error', () => finish(false));
  });
}

/** First port from `start` that nothing on 127.0.0.1 is listening on. */
async function freePort(start, attempts = 20) {
  for (let port = start; port < start + attempts; port++) {
    if (!(await canConnect('127.0.0.1', port, 500))) return port;
  }
  throw new CliError(`No free port found in ${start}-${start + attempts - 1} for Postgres.`);
}

export const CREATE_HELP = `
Usage: nestlaravel create <name> [options]

Creates a new NestLaravel workspace (Nx + Laravel gateway + Kafka kit).

Options:
  --db <sqlite|pgsql|mysql>  Database for the gateway on the host (default: sqlite)
  --with-portals             Include the Next.js customer/admin portals
  --migrate                  Run migrations and seed roles after install
                             (with --db pgsql and Docker running, starts the project's Postgres container first)
  --skip-install             Do not run composer/npm install
  --skip-git                 Do not run "git init"
  --skip-validate            Do not run the initial test run
  --skip-checks              Do not verify Node/PHP/Composer first (not recommended)
  -y, --yes                  Non-interactive
`;

export async function create(argv) {
  const { positionals, flags } = parseArgs(argv, {
    boolean: ['yes', 'skip-install', 'skip-git', 'skip-validate', 'skip-checks', 'with-portals', 'migrate', 'help'],
    alias: { y: 'yes', h: 'help' },
  });
  if (flags.help) return void console.log(CREATE_HELP);

  const name = validateName(positionals[0], { kind: 'project name', allowReserved: true });
  const db = String(flags.db ?? 'sqlite');
  if (!DB_CHOICES.includes(db)) throw new CliError(`--db must be one of: ${DB_CHOICES.join(', ')}`);

  const root = resolve(process.cwd(), name);
  if (!isEmptyDir(root)) throw new CliError(`Directory "${name}" already exists and is not empty.`);

  log.info(`\n${c.bold('NestLaravel')} ${FRAMEWORK_VERSION} — creating ${c.cyan(name)}\n`);

  // 1-5. System requirements ---------------------------------------------------------------------
  if (!flags['skip-checks']) {
    log.step('Checking system requirements');
    const failed = printRequirements(checkRequirements({ db }));
    if (failed.length) {
      throw new CliError(`Fix the ${failed.length} missing requirement(s) above and re-run (see "nestlaravel doctor").`);
    }
    log.blank();
  }

  // --db pgsql --migrate with a running Docker daemon: the project's own Postgres container is started before
  // migrating. Its host port must be free — another Postgres on 5432 would answer with the wrong credentials.
  const dockerPostgres = db === 'pgsql' && flags.migrate && !flags['skip-install']
    && capture('docker', ['info', '--format', '{{.ServerVersion}}'], { timeout: 8000 }) != null;
  if (dockerPostgres) {
    // A volume left by an earlier project of the same name keeps its old password (Postgres applies
    // POSTGRES_PASSWORD only on first init), so migrating against it would fail authentication.
    const volume = `${name}_postgres_data`;
    if (capture('docker', ['volume', 'inspect', volume]) != null) {
      throw new CliError(
        `Docker volume "${volume}" is left over from an earlier "${name}" project and holds a different database ` +
          `password. Remove it if that data can go (docker volume rm ${volume}), pick another project name, or drop --migrate.`,
      );
    }
  }

  // 6-8. Scaffold workspace -------------------------------------------------------------------------
  log.step('Scaffolding workspace');
  const tpl = templatesDir();
  ensureDir(root);
  copyTree(join(tpl, 'workspace'), root);
  copyTree(join(tpl, 'service-template'), join(root, '.nestlaravel', 'service-template'));
  if (flags['with-portals']) copyTree(join(tpl, 'optional', 'portals'), root);

  const vars = { name, version: FRAMEWORK_VERSION };
  const files = join(tpl, 'project-files');
  copyTree(join(files, 'nx.json'), join(root, 'nx.json'), { overwrite: true });
  copyTree(join(files, '_gitignore'), join(root, '.gitignore'), { overwrite: true });
  writeFileSync(join(root, 'README.md'), render(readFileSync(join(files, 'README.md.tpl'), 'utf8'), vars));
  ensureDir(join(root, '.github', 'workflows'));
  writeFileSync(join(root, '.github', 'workflows', 'ci.yml'), readFileSync(join(files, '.github', 'workflows', 'ci.yml.tpl'), 'utf8'));

  const cliSpec = process.env.NESTLARAVEL_CLI_SPEC || `^${FRAMEWORK_VERSION}`;
  const pkg = {
    name,
    version: '0.1.0',
    private: true,
    description: 'NestLaravel workspace',
    scripts: {
      dev: 'nestlaravel dev',
      build: 'nestlaravel build',
      test: 'nestlaravel test',
      lint: 'nestlaravel lint',
    },
    engines: { node: `>=${RUNTIME.node.min}` },
    devDependencies: { nestlaravel: cliSpec, nx: RUNTIME.nx },
  };
  if (flags['with-portals']) pkg.workspaces = ['apps/customer-portal', 'apps/admin-portal', 'packages/api-client'];
  writeFileSync(join(root, 'package.json'), `${JSON.stringify(pkg, null, 2)}\n`);

  writeManifest(root, {
    nestlaravel: FRAMEWORK_VERSION,
    createdWith: FRAMEWORK_VERSION,
    name,
    database: db,
    portals: Boolean(flags['with-portals']),
    services: [],
    features: [],
  });
  recordManaged(root, managedMap(tpl, []));
  log.ok(`Workspace files written to ${name}/`);

  // 9-11. Environment, secrets, database -------------------------------------------------------------
  log.step('Generating environment files and application secrets');
  const dbPort = dockerPostgres ? await freePort(5432) : db === 'pgsql' ? 5432 : 3306;
  if (dockerPostgres && dbPort !== 5432) log.warn(`Port 5432 is in use; the project's Postgres will listen on 127.0.0.1:${dbPort}.`);
  const dbPassword = secret(24);
  const redisPassword = secret(24);

  // Root compose env
  let rootEnv = readFileSync(join(root, '.env.example'), 'utf8');
  rootEnv = setEnv(rootEnv, 'DB_PASSWORD', dbPassword);
  rootEnv = setEnv(rootEnv, 'REDIS_PASSWORD', redisPassword);
  rootEnv = setEnv(rootEnv, 'COMPOSE_PROJECT_NAME', name);
  if (db === 'pgsql') rootEnv = setEnv(rootEnv, 'POSTGRES_PORT', String(dbPort));
  writeIfAbsent(join(root, '.env'), rootEnv);

  // Gateway env
  let apiEnv = readFileSync(join(root, 'apps', 'api', '.env.example'), 'utf8');
  apiEnv = setEnv(apiEnv, 'APP_KEY', appKey());
  apiEnv = setEnv(apiEnv, 'APP_NAME', 'gateway');
  apiEnv = setEnv(apiEnv, 'DB_CONNECTION', db);
  if (db !== 'sqlite') {
    apiEnv = setEnv(apiEnv, 'DB_HOST', '127.0.0.1');
    apiEnv = setEnv(apiEnv, 'DB_PORT', String(dbPort));
    apiEnv = setEnv(apiEnv, 'DB_DATABASE', 'app');
    apiEnv = setEnv(apiEnv, 'DB_USERNAME', 'app');
    apiEnv = setEnv(apiEnv, 'DB_PASSWORD', dbPassword);
  }
  apiEnv = setEnv(apiEnv, 'REDIS_PASSWORD', redisPassword);
  writeIfAbsent(join(root, 'apps', 'api', '.env'), apiEnv);
  if (db === 'sqlite') writeIfAbsent(join(root, 'apps', 'api', 'database', 'database.sqlite'), '');
  log.ok('.env files created with fresh random secrets (git-ignored)');

  // 12-13. Dependencies ---------------------------------------------------------------------------------
  if (!flags['skip-install']) {
    log.step('Installing PHP dependencies (apps/api)');
    await run('composer', ['install', '--no-interaction', '--prefer-dist', '--no-progress'], { cwd: join(root, 'apps', 'api') });
    log.step('Installing Node dependencies (Nx)');
    await run('npm', ['install', '--no-audit', '--no-fund'], { cwd: root });
  } else {
    log.warn('Skipped dependency installation (--skip-install). Run composer install in apps/api and npm install in the root.');
  }

  // 14. Migrations -------------------------------------------------------------------------------------
  if (flags.migrate && !flags['skip-install']) {
    const api = join(root, 'apps', 'api');
    if (dockerPostgres) {
      log.step(`Starting Postgres (docker compose, 127.0.0.1:${dbPort})`);
      await run('docker', ['compose', '-f', 'docker-compose.infra.yml', 'up', '-d', '--wait', 'postgres'], { cwd: root });
    }
    if (db !== 'sqlite' && !(await canConnect('127.0.0.1', dbPort))) {
      throw new CliError(
        `Workspace created, but nothing is listening on 127.0.0.1:${dbPort}, so migrations were not run.\n` +
          (db === 'pgsql'
            ? `  Start Postgres, then migrate:\n    cd ${name}\n    docker compose -f docker-compose.infra.yml up -d postgres\n`
            : `  Start MySQL with database/user "app" and the DB_PASSWORD from apps/api/.env, then:\n    cd ${name}\n`) +
          '    cd apps/api && php artisan migrate && php artisan db:seed --class="Database\\Seeders\\RolePermissionSeeder"',
      );
    }
    log.step('Running migrations and seeding roles');
    await run('php', ['artisan', 'migrate', '--force', '--no-interaction'], { cwd: api });
    await run('php', ['artisan', 'db:seed', '--class=Database\\Seeders\\RolePermissionSeeder', '--force'], { cwd: api });
  }

  // git
  if (!flags['skip-git'] && capture('git', ['--version'])) {
    await run('git', ['init', '-q'], { cwd: root, allowFailure: true, silent: true });
  }

  // 16. Validation --------------------------------------------------------------------------------------
  if (!flags['skip-install'] && !flags['skip-validate']) {
    log.step('Validating the new workspace');
    await nx(['show', 'projects'], { cwd: root });
    await run('php', ['artisan', 'test'], { cwd: join(root, 'apps', 'api') });
    log.ok('Workspace validated');
  }

  // 17. Next steps ---------------------------------------------------------------------------------------
  log.blank();
  log.ok(`${c.bold(name)} is ready.`);
  log.blank();
  log.info('Next steps:');
  log.cmd(`cd ${name}`);
  log.cmd('npx nestlaravel generate service orders');
  log.cmd('npx nestlaravel dev');
  log.blank();
  log.info(c.dim(`Docs: https://nestlaravel.intelfric.com  ·  Manifest: ${MANIFEST}`));
  if (!existsSync(join(root, 'apps', 'api', 'vendor'))) {
    log.warn('Dependencies are not installed yet — run composer install (apps/api) and npm install.');
  }
}
