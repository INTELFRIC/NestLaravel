import assert from 'node:assert/strict';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { checkWorkspace } from '../src/doctor.js';
import { laravelApps, selectApps, verdict, workspaceFindings } from '../src/commands/ops.js';
import { managedMap } from '../src/managed.js';
import { generateEvent } from '../src/generators/event.js';

function workspace() {
  const root = mkdtempSync(join(tmpdir(), 'nl-ops-'));
  const app = join(root, 'apps', 'orders-service');
  mkdirSync(join(app, 'app', 'Modules', 'Orders'), { recursive: true });
  mkdirSync(join(app, 'config'), { recursive: true });
  writeFileSync(join(app, 'artisan'), '<?php');
  writeFileSync(
    join(app, 'config', 'kafka.php'),
    "<?php\nreturn [\n    'events' => [\n        // @nestlaravel:events\n    ],\n];\n",
  );
  return { root, app };
}

test('generate event: schema class, registered once in config/kafka.php', async () => {
  const { root, app } = workspace();
  await generateEvent(root, 'order.created', { service: 'orders' });
  await generateEvent(root, 'order.created', { service: 'orders', force: true });

  const src = readFileSync(join(app, 'app/Modules/Orders/Domain/Events/OrderCreated.php'), 'utf8');
  assert.match(src, /final class OrderCreated extends AbstractDomainEvent implements HasEventSchema/);
  assert.match(src, /new EventSchema\('order\.created', 1, \[/);
  assert.match(src, /public function version\(\): int\s*\{\s*return 1;/);

  const cfg = readFileSync(join(app, 'config/kafka.php'), 'utf8');
  const ref = '\\App\\Modules\\Orders\\Domain\\Events\\OrderCreated::class,';
  assert.equal(cfg.split(ref).length - 1, 1, 'registered exactly once');
  assert.ok(cfg.indexOf(ref) < cfg.indexOf('// @nestlaravel:events'), 'inserted before the marker');
});

test('generate event --version 2 creates a NEW class next to v1 and leaves v1 untouched', async () => {
  const { root, app } = workspace();
  await generateEvent(root, 'order.created', { service: 'orders' });
  const v1 = readFileSync(join(app, 'app/Modules/Orders/Domain/Events/OrderCreated.php'), 'utf8');

  await generateEvent(root, 'order.created', { service: 'orders', version: '2' });
  const v2 = readFileSync(join(app, 'app/Modules/Orders/Domain/Events/OrderCreatedV2.php'), 'utf8');
  assert.match(v2, /final class OrderCreatedV2/);
  assert.match(v2, /new EventSchema\('order\.created', 2, \[/);
  assert.equal(readFileSync(join(app, 'app/Modules/Orders/Domain/Events/OrderCreated.php'), 'utf8'), v1);

  const cfg = readFileSync(join(app, 'config/kafka.php'), 'utf8');
  assert.match(cfg, /OrderCreated::class,/);
  assert.match(cfg, /OrderCreatedV2::class,/);
});

test('generate event rejects bad input, existing classes, the gateway and unknown versions', async () => {
  const { root } = workspace();
  await assert.rejects(() => generateEvent(root, 'Order Created', { service: 'orders' }), /Invalid event type/);
  await assert.rejects(() => generateEvent(root, 'order.created', { service: 'orders', version: '0' }), /Invalid --version/);
  await assert.rejects(() => generateEvent(root, 'order.created', { service: 'orders', version: 'x' }), /Invalid --version/);
  await assert.rejects(() => generateEvent(root, 'order.created', {}), /--service/);
  await assert.rejects(() => generateEvent(root, 'order.created', { service: '../etc' }), /Invalid service name/);
  mkdirSync(join(root, 'apps', 'api'), { recursive: true });
  await assert.rejects(() => generateEvent(root, 'order.created', { service: 'api' }), /generate kafka-event/);
  await generateEvent(root, 'order.created', { service: 'orders' });
  await assert.rejects(() => generateEvent(root, 'order.created', { service: 'orders' }), /already exists/);
});

test('generate event tells the user when the registration marker is missing instead of silently skipping', async () => {
  const { root, app } = workspace();
  writeFileSync(join(app, 'config/kafka.php'), "<?php\nreturn ['events' => []];\n");
  await assert.rejects(() => generateEvent(root, 'order.created', { service: 'orders' }), /marker/);
});

test('verdict: any FAIL blocks, WARN passes with a label, never claims more than "no findings"', () => {
  assert.deepEqual(verdict([{ status: 'pass' }]), { pass: 1, warn: 0, fail: 0, label: 'NO FINDINGS', exitCode: 0 });
  assert.equal(verdict([{ status: 'pass' }, { status: 'warn' }]).label, 'READY WITH WARNINGS');
  assert.equal(verdict([{ status: 'pass' }, { status: 'warn' }]).exitCode, 0);
  const bad = verdict([{ status: 'fail' }, { status: 'warn' }, { status: 'pass' }]);
  assert.equal(bad.label, 'NOT READY');
  assert.equal(bad.exitCode, 1);
});

test('selectApps: finds services by short name, gateway alias, and rejects unknown names', () => {
  const { root } = workspace();
  mkdirSync(join(root, 'apps', 'api'), { recursive: true });
  writeFileSync(join(root, 'apps', 'api', 'artisan'), '<?php');

  assert.deepEqual(laravelApps(root).map((a) => a.name), ['api', 'orders-service']);
  assert.deepEqual(selectApps(root, 'orders').map((a) => a.name), ['orders-service']);
  assert.deepEqual(selectApps(root, 'gateway').map((a) => a.name), ['api']);
  assert.equal(selectApps(root).length, 2);
  assert.throws(() => selectApps(root, 'nope'), /No app "nope"/);
});

test('workspace findings: missing .gitignore for .env is a FAIL; compose without stop_grace_period is a WARN', () => {
  const root = mkdtempSync(join(tmpdir(), 'nl-ws-find-'));
  writeFileSync(join(root, 'docker-compose.yml'), 'services: {}\n');
  let f = workspaceFindings(root);
  assert.ok(f.some((x) => x.status === 'fail' && /\.env/.test(x.message)));
  assert.ok(f.some((x) => x.status === 'warn' && /stop_grace_period/.test(x.message)));

  writeFileSync(join(root, '.gitignore'), 'node_modules\n.env\n');
  writeFileSync(join(root, 'docker-compose.yml'), 'services:\n  a:\n    stop_grace_period: 40s\n');
  f = workspaceFindings(root);
  assert.ok(!f.some((x) => x.status === 'fail'));
  assert.ok(f.some((x) => x.status === 'pass' && /stop_grace_period/.test(x.message)));
});

test('doctor workspace check flags short service secrets, empty APP_KEY and debug in production', () => {
  const { root, app } = workspace();
  mkdirSync(join(app, 'vendor'), { recursive: true });
  writeFileSync(join(app, 'vendor', 'autoload.php'), '<?php');
  writeFileSync(join(app, '.env'), 'APP_KEY=\nINTERNAL_SERVICE_SECRET=short\nAPP_ENV=production\nAPP_DEBUG=true\n');

  const [r] = checkWorkspace(root);
  assert.equal(r.ok, false);
  assert.match(r.message, /APP_KEY empty/);
  assert.match(r.message, /INTERNAL_SERVICE_SECRET/);
  assert.match(r.message, /APP_DEBUG=true in production/);

  writeFileSync(join(app, '.env'), `APP_KEY=base64:abc\nINTERNAL_SERVICE_SECRET=${'x'.repeat(40)}\nAPP_ENV=production\nAPP_DEBUG=false\n`);
  assert.equal(checkWorkspace(root)[0].ok, true);
  assert.ok(existsSync(join(app, '.env')));
});

test('migration 1.1.0: patches compose once, adds METRICS_TOKEN once, only warns about behaviour switches', async () => {
  const { migrations } = await import('../src/migrations/index.js');
  const migration = migrations.find((m) => m.version === '1.1.0');
  assert.ok(migration, '1.1.0 migration is registered');

  const root = mkdtempSync(join(tmpdir(), 'nl-mig-'));
  const templates = mkdtempSync(join(tmpdir(), 'nl-tpl-'));
  mkdirSync(join(templates, 'workspace/infrastructure/k8s'), { recursive: true });
  writeFileSync(join(templates, 'workspace/infrastructure/k8s/service.yaml'), 'kind: Deployment\n');

  mkdirSync(join(root, 'apps/api/config'), { recursive: true });
  writeFileSync(
    join(root, 'apps/api/config/gateway.php'),
    "<?php\nreturn ['services' => [\n        'orders' => [\n            'enabled' => (bool) env('GATEWAY_ORDERS_ENABLED', true),\n        ],\n]];\n",
  );
  mkdirSync(join(root, 'apps/orders-service'), { recursive: true });
  writeFileSync(join(root, 'apps/orders-service/artisan'), '<?php');
  writeFileSync(join(root, 'apps/orders-service/.env'), 'APP_KEY=x\n');
  writeFileSync(join(root, 'apps/api/.env'), 'APP_KEY=y\nMETRICS_TOKEN=keep-me\n');
  writeFileSync(join(root, 'docker-compose.yml'), 'x-laravel-app: &laravel-app\n  image: x\n  restart: unless-stopped\n  networks: [internal]\n');

  const warnings = [];
  const ctx = {
    root,
    templates,
    warn: (m) => warnings.push(m),
    write: (f, c) => writeFileSync(f, c),
    edit: (f, fn) => writeFileSync(f, fn(readFileSync(f, 'utf8'))),
  };

  const run = () => {
    const applied = [];
    for (const step of migration.steps) {
      if (step.needed(ctx)) {
        step.apply(ctx);
        applied.push(step.title);
      }
    }
    return applied;
  };

  assert.equal(run().length, 4);
  assert.ok(existsSync(join(root, 'infrastructure/k8s/service.yaml')));
  assert.match(readFileSync(join(root, 'docker-compose.yml'), 'utf8'), /restart: unless-stopped\n {2}stop_grace_period: 40s\n/);
  assert.match(readFileSync(join(root, 'apps/orders-service/.env'), 'utf8'), /^METRICS_TOKEN=\S{20,}$/m);
  assert.match(readFileSync(join(root, 'apps/api/.env'), 'utf8'), /METRICS_TOKEN=keep-me/, 'an existing token is never replaced');
  assert.equal(warnings.length, 1);
  assert.match(warnings[0], /php artisan migrate.*FIRST/s);
  assert.doesNotMatch(readFileSync(join(root, 'apps/orders-service/.env'), 'utf8'), /KAFKA_INBOX_ENABLED/, 'behaviour switches are reported, never set');

  // Idempotent: the concrete edits are not needed again (only the informational step keeps reporting).
  const second = run();
  assert.equal(second.length, 1);
  assert.match(second[0], /Review runtime switches/);
  assert.equal(readFileSync(join(root, 'docker-compose.yml'), 'utf8').split('stop_grace_period').length - 1, 1);
});

test('ops commands forward positional arguments and options to artisan, keeping --service for ourselves', async () => {
  const { splitArgs } = await import('../src/commands/ops.js');
  assert.deepEqual(splitArgs(['orders.events', '--service', 'orders', '--limit', '5', '--json']), {
    service: 'orders',
    help: false,
    passthrough: ['orders.events', '--limit=5', '--json'],
  });
  assert.deepEqual(splitArgs(['--failed', '--requeue']).passthrough, ['--failed', '--requeue']);
  assert.equal(splitArgs(['--help']).help, true);
  assert.deepEqual(splitArgs(['--', '--raw']).passthrough, ['--raw']);
});

test('managed sync covers every runtime directory of the kafka kit (routes/ops.php was once missed on upgrades)', () => {
  const tpl = mkdtempSync(join(tmpdir(), 'nl-tpl-kit-'));
  for (const rel of ['src/A.php', 'config/kafka.php', 'database/migrations/m.php', 'routes/ops.php']) {
    mkdirSync(join(tpl, 'workspace/packages/laravel-kafka', rel, '..'), { recursive: true });
    writeFileSync(join(tpl, 'workspace/packages/laravel-kafka', rel), '<?php');
  }
  const dests = managedMap(tpl, []).map((m) => m.to);
  assert.ok(dests.includes('packages/laravel-kafka/routes/ops.php'));
  assert.ok(dests.includes('packages/laravel-kafka/src/A.php'));
});

test('every top-level entry the kafka kit ships is either synced by update or deliberately unmanaged', async () => {
  const { readdirSync } = await import('node:fs');
  const kit = join(import.meta.dirname, '../../laravel-kafka');
  const unmanaged = new Set(['tests', 'vendor', 'benchmarks', 'composer.json', 'composer.lock', 'phpunit.xml', 'LICENSE', '.phpunit.cache']);
  const tpl = mkdtempSync(join(tmpdir(), 'nl-tpl-all-'));
  const shipped = readdirSync(kit, { withFileTypes: true }).filter((e) => e.isDirectory() && !unmanaged.has(e.name));
  for (const dir of shipped) {
    mkdirSync(join(tpl, 'workspace/packages/laravel-kafka', dir.name), { recursive: true });
    writeFileSync(join(tpl, 'workspace/packages/laravel-kafka', dir.name, 'x.php'), '<?php');
  }
  const dests = managedMap(tpl, []).map((m) => m.to);
  for (const dir of shipped) {
    assert.ok(dests.includes(`packages/laravel-kafka/${dir.name}/x.php`), `update does not sync packages/laravel-kafka/${dir.name}/`);
  }
});
