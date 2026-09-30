#!/usr/bin/env node
// End-to-end "clean machine" test of the PUBLISHED ARTEFACT (the npm tarball), not the source tree.
//
//   node scripts/e2e-clean-install.mjs            # full run
//   E2E_KEEP=1 node scripts/e2e-clean-install.mjs # keep the temp dir for inspection
//
// 1. build templates + npm pack  2. in an empty temp dir: `npx <tarball> nestlaravel create`
// 3. generate service / kafka-topic / kafka-event  4. run the generated project's tests, lint, nx graph
// 5. `update --dry-run` must report "up to date"   6. leak checks (no .env / vendor in the tarball)
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const cliDir = join(repo, 'packages', 'cli');
const isWin = process.platform === 'win32';
const work = mkdtempSync(join(tmpdir(), 'nestlaravel-e2e-'));
let step = 0;

function sh(cmd, args, opts = {}) {
  const label = `${cmd} ${args.join(' ')}`.slice(0, 140);
  console.log(`\n[${++step}] ${label}`);
  const shell = isWin && /^(npm|npx)$/.test(cmd);
  const result = spawnSync(shell ? `${cmd} ${args.map((a) => (/[\s"]/.test(a) ? `"${a}"` : a)).join(' ')}` : cmd, shell ? [] : args, {
    cwd: opts.cwd ?? work,
    stdio: opts.capture ? ['ignore', 'pipe', 'inherit'] : 'inherit',
    encoding: 'utf8',
    shell,
    env: { ...process.env, ...opts.env },
  });
  if (result.status !== 0 && !opts.allowFail) {
    console.error(`\n✖ step ${step} failed (exit ${result.status}): ${label}`);
    process.exit(1);
  }
  return result;
}
const assert = (cond, message) => {
  if (!cond) {
    console.error(`\n✖ assertion failed: ${message}`);
    process.exit(1);
  }
  console.log(`   ✔ ${message}`);
};

try {
  // 1. Pack the real artefact -------------------------------------------------------------------------
  sh('npm', ['run', 'build:templates'], { cwd: cliDir });
  sh('node', ['scripts/verify-package.mjs'], { cwd: cliDir });
  const packed = sh('npm', ['pack', '--pack-destination', work, '--json', '--ignore-scripts'], { cwd: cliDir, capture: true });
  const tgz = join(work, JSON.parse(packed.stdout)[0].filename);
  assert(existsSync(tgz), `tarball created (${tgz})`);

  // 2. Create a project from the tarball in an empty dir ----------------------------------------------------
  const env = { NESTLARAVEL_CLI_SPEC: `file:${tgz}` };
  sh('npm', ['exec', '--yes', `--package=${tgz}`, '--', 'nestlaravel', 'create', 'e2e-app', '--migrate'], { env });
  const app = join(work, 'e2e-app');
  for (const f of ['nestlaravel.json', 'nx.json', 'docker-compose.yml', 'apps/api/vendor/autoload.php', 'apps/api/.env', '.env', 'node_modules/nx/package.json']) {
    assert(existsSync(join(app, f)), `created: ${f}`);
  }
  assert(!/^APP_KEY=$/m.test(readFileSync(join(app, 'apps/api/.env'), 'utf8')), 'gateway APP_KEY was generated');
  assert(!existsSync(join(app, 'apps/api/.env.example.bak')), 'no stray files');

  const cli = ['exec', '--no', '--', 'nestlaravel'];

  // 3. Generators -------------------------------------------------------------------------------------------------
  sh('npm', [...cli, 'generate', 'service', 'orders'], { cwd: app });
  sh('npm', [...cli, 'generate', 'service', 'users'], { cwd: app });
  sh('npm', [...cli, 'generate', 'kafka-topic', 'order-events', '--service', 'orders'], { cwd: app });
  sh('npm', [...cli, 'generate', 'kafka-event', 'order.created', '--service', 'orders', '--consumer'], { cwd: app });
  for (const f of [
    'apps/orders-service/artisan',
    'apps/orders-service/vendor/autoload.php',
    'apps/orders-service/.env',
    'apps/orders-service/app/Http/Middleware/VerifyGatewaySignature.php',
    'apps/orders-service/app/Modules/Orders/Domain/Events/OrderCreated.php',
    'apps/orders-service/app/Modules/Orders/Infrastructure/Messaging/OrderCreatedHandler.php',
    'apps/users-service/project.json',
  ]) assert(existsSync(join(app, f)), `generated: ${f}`);

  const gateway = readFileSync(join(app, 'apps/api/config/gateway.php'), 'utf8');
  assert(gateway.includes("'orders' =>") && gateway.includes('ORDERS_SERVICE_SECRET'), 'gateway registers orders with a signing secret');
  const apiEnv = readFileSync(join(app, 'apps/api/.env'), 'utf8');
  const svcEnv = readFileSync(join(app, 'apps/orders-service/.env'), 'utf8');
  const gwSecret = apiEnv.match(/^ORDERS_SERVICE_SECRET=(.+)$/m)?.[1];
  const svSecret = svcEnv.match(/^INTERNAL_SERVICE_SECRET=(.+)$/m)?.[1];
  assert(gwSecret && gwSecret === svSecret && gwSecret.length >= 64, 'gateway and service share a 256-bit signing secret');
  const compose = readFileSync(join(app, 'docker-compose.yml'), 'utf8');
  const block = compose.slice(compose.indexOf('  orders-service:'), compose.indexOf('  orders-service-outbox:'));
  assert(block.includes('APP_DIR: apps/orders-service') && !/^\s+ports:/m.test(block), 'compose: internal service has no published ports');

  // 4. Generated project's own quality gates ---------------------------------------------------------------------
  sh('npm', [...cli, 'doctor'], { cwd: app });
  sh('php', ['artisan', 'route:list', '--path=api/v1', '--no-interaction'], { cwd: join(app, 'apps/orders-service') });
  sh('npm', [...cli, 'test'], { cwd: app });
  sh('npm', [...cli, 'lint'], { cwd: app });
  sh('npm', ['exec', '--no', '--', 'nx', 'graph', '--file=graph.json'], { cwd: app });
  const graph = JSON.parse(readFileSync(join(app, 'graph.json'), 'utf8'));
  const projects = Object.keys(graph.graph.nodes).sort();
  assert(['api', 'orders-service', 'users-service'].every((p) => projects.includes(p)), `nx graph knows ${projects.join(', ')}`);

  // 5. Upgrade path is a no-op on a fresh project -------------------------------------------------------------------
  const upd = sh('npm', [...cli, 'update', '--dry-run'], { cwd: app, capture: true });
  assert(/up to date|Dry run/i.test(upd.stdout), '`update --dry-run` runs cleanly on a new project');

  // 6. Leak checks ---------------------------------------------------------------------------------------------------------
  const dry = sh('npm', ['pack', '--dry-run', '--json', '--ignore-scripts'], { cwd: cliDir, capture: true });
  const listing = JSON.parse(dry.stdout)[0].files.map((f) => f.path);
  const envFiles = listing.filter((p) => /(^|\/)\.env(\..+)?$/.test(p) && !p.endsWith('.env.example'));
  assert(envFiles.length === 0, `tarball has no .env files (${envFiles.join(', ') || 'none'})`);
  assert(!listing.some((p) => /(^|\/)(vendor|node_modules)\//.test(p)), 'tarball has no vendor/ or node_modules/');
  assert(!listing.some((p) => /\.(pem|key|sqlite|log)$/.test(p)), 'tarball has no keys, databases or logs');

  console.log('\n✔ clean-install E2E passed');
} finally {
  if (process.env.E2E_KEEP) console.log(`\n(kept) ${work}`);
  else rmSync(work, { recursive: true, force: true });
}
