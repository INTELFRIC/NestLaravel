import assert from 'node:assert/strict';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { missingClients, planServe, projectsWithDetails } from '../src/commands/tasks.js';
import { addPortals, generateApp } from '../src/generators/app.js';
import { getEnv } from '../src/secrets.js';

const templatesBuilt = existsSync(join(dirname(fileURLToPath(import.meta.url)), '..', 'templates', '.built'));

function workspace() {
  const root = mkdtempSync(join(tmpdir(), 'nl-clients-'));
  mkdirSync(join(root, 'apps', 'api'), { recursive: true });
  const env = 'APP_NAME=gateway\nCORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:3001\n';
  writeFileSync(join(root, 'apps', 'api', '.env.example'), env);
  writeFileSync(join(root, 'apps', 'api', '.env'), env);
  writeFileSync(join(root, 'package.json'), JSON.stringify({ name: 'ws', private: true }));
  writeFileSync(join(root, 'nestlaravel.json'), JSON.stringify({ name: 'ws', portals: false, services: [], features: [] }));
  return root;
}
const read = (root, ...p) => readFileSync(join(root, ...p), 'utf8');
const json = (root, ...p) => JSON.parse(read(root, ...p));
const origins = (root, file) => getEnv(read(root, 'apps/api', file), 'CORS_ALLOWED_ORIGINS').split(',');

test('generate app web: Next.js app with a serve target, gateway URL, CORS and workspace entry', async () => {
  const root = workspace();
  await generateApp(root, 'web', 'storefront', { 'skip-install': true });
  const project = json(root, 'apps/storefront/project.json');
  assert.equal(project.name, 'storefront');
  assert.equal(project.targets.serve.options.command, 'npm run dev');
  assert.equal(project.targets.serve.options.cwd, 'apps/storefront');
  assert.match(read(root, 'apps/storefront/package.json'), /next dev -p 3002/);
  assert.match(read(root, 'apps/storefront/.env.local'), /NEXT_PUBLIC_API_URL=http:\/\/localhost:8000\/api/);
  for (const f of ['.env', '.env.example']) {
    const list = origins(root, f);
    for (const o of ['http://localhost:3002', 'http://127.0.0.1:3002', 'http://localhost:3000']) assert.ok(list.includes(o), `${f} ${o}`);
  }
  assert.deepEqual(json(root, 'package.json').workspaces, ['apps/storefront']);
  assert.deepEqual(json(root, 'nestlaravel.json').clients, [{ name: 'storefront', kind: 'web', stack: 'next', port: 3002 }]);
  assert.deepEqual(projectsWithDetails(root, 'serve').map((p) => p.name), ['storefront']);
});

test('generate app: next free port, and duplicates, bad names and clashing ports are rejected', async () => {
  const root = workspace();
  await generateApp(root, 'web', 'one', { 'skip-install': true });
  await generateApp(root, 'web', 'two', { 'skip-install': true });
  assert.match(read(root, 'apps/two/package.json'), /next dev -p 3003/);
  await assert.rejects(() => generateApp(root, 'web', 'one', { 'skip-install': true }), /already exists/);
  await assert.rejects(() => generateApp(root, 'web', 'three', { 'skip-install': true, port: 3002 }), /already used/);
  await assert.rejects(() => generateApp(root, 'web', '../evil', { 'skip-install': true }), /Invalid/);
  await assert.rejects(() => generateApp(root, 'desktop', 'x', {}), /Unknown app type/);
  assert.equal(origins(root, '.env').filter((o) => o === 'http://localhost:3002').length, 1);
});

test('generate app mobile: Flutter sources, an unattended serve target, a device target for phones', async () => {
  const root = workspace();
  await generateApp(root, 'mobile', undefined, { 'skip-install': true });
  const project = json(root, 'apps/mobile/project.json');
  assert.match(project.targets.serve.options.command, /flutter run -d web-server .*--web-port 8090 .*--dart-define=API_URL=http:\/\/localhost:8000\/api/);
  assert.match(project.targets.device.options.command, /10\.0\.2\.2:8000/);
  assert.ok(project.tags.includes('stack:flutter'));
  assert.ok(existsSync(join(root, 'apps/mobile/lib/main.dart')));
  assert.match(read(root, 'apps/mobile/lib/api_client.dart'), /defaultValue: 'http:\/\/localhost:8000\/api'/);
  assert.match(read(root, 'apps/mobile/pubspec.yaml'), /^name: mobile$/m);
  assert.ok(origins(root, '.env.example').includes('http://localhost:8090'));
  assert.equal(json(root, 'package.json').workspaces, undefined, 'Flutter apps are not npm workspaces');
});

test('dev plan: serves clients with a serve target, skips Flutter without an SDK and Next.js without dependencies', async () => {
  const root = workspace();
  await generateApp(root, 'web', 'site', { 'skip-install': true });
  await generateApp(root, 'mobile', 'app', { 'skip-install': true });
  let plan = planServe(root, { hasFlutter: () => false });
  assert.deepEqual(plan.serve, []);
  assert.deepEqual(plan.skipped.map((s) => s.name).sort(), ['app', 'site']);
  assert.match(plan.skipped.find((s) => s.name === 'site').reason, /npm install/);
  assert.match(plan.skipped.find((s) => s.name === 'app').reason, /Flutter/);

  mkdirSync(join(root, 'node_modules', 'next'), { recursive: true });
  plan = planServe(root, { hasFlutter: () => true });
  assert.deepEqual(plan.serve.sort(), ['app', 'site']);
  assert.deepEqual(plan.skipped, []);
});

test('dev warns about recorded clients whose folder is gone', async () => {
  const root = workspace();
  await generateApp(root, 'mobile', 'app', { 'skip-install': true });
  assert.deepEqual(missingClients(root), []);
  writeFileSync(join(root, 'nestlaravel.json'), JSON.stringify({ clients: [{ name: 'ghost' }] }));
  assert.deepEqual(missingClients(root), ['ghost']);
});

test('add portals: copies both portals once, never overwrites edits, records them and adds workspaces', { skip: !templatesBuilt && 'templates not built' }, async () => {
  const root = workspace();
  await addPortals(root, { 'skip-install': true });
  for (const p of ['customer-portal', 'admin-portal']) {
    assert.equal(json(root, 'apps', p, 'project.json').targets.serve.options.command, 'npm run dev');
    assert.ok(existsSync(join(root, 'apps', p, '.env.local')));
  }
  assert.ok(existsSync(join(root, 'packages/api-client/package.json')));
  assert.deepEqual(json(root, 'package.json').workspaces, ['apps/customer-portal', 'apps/admin-portal', 'packages/api-client']);
  const manifest = json(root, 'nestlaravel.json');
  assert.equal(manifest.portals, true);
  assert.deepEqual(manifest.clients.map((x) => x.name), ['customer-portal', 'admin-portal']);
  assert.ok(origins(root, '.env').includes('http://127.0.0.1:3001'));

  writeFileSync(join(root, 'apps/customer-portal/README.md'), 'mine');
  await addPortals(root, { 'skip-install': true });
  assert.equal(read(root, 'apps/customer-portal/README.md'), 'mine');
  assert.equal(json(root, 'package.json').workspaces.length, 3);
  assert.equal(json(root, 'nestlaravel.json').clients.length, 2);
});
