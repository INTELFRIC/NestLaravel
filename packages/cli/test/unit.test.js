import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { parseArgs } from '../src/args.js';
import { compareVersions } from '../src/doctor.js';
import { generateKafkaEvent, generateKafkaTopic } from '../src/generators/kafka.js';
import { managedMap, recordManaged, sha, syncManaged } from '../src/managed.js';
import { appKey, getEnv, secret, setEnv } from '../src/secrets.js';
import { toEnvName, toStudly, validateName } from '../src/workspace.js';

test('parseArgs: positionals, flags, negation, values', () => {
  const r = parseArgs(['service', 'users', '--port', '8005', '--no-infra', '-y', '--db=pgsql', '--', '--raw'], { boolean: ['yes'], alias: { y: 'yes' } });
  assert.deepEqual(r.positionals, ['service', 'users']);
  assert.equal(r.flags.port, '8005');
  assert.equal(r.flags.infra, false);
  assert.equal(r.flags.yes, true);
  assert.equal(r.flags.db, 'pgsql');
  assert.deepEqual(r.rest, ['--raw']);
});

test('parseArgs: a declared boolean flag does not swallow the next positional', () => {
  const r = parseArgs(['--skip-install', 'my-app'], { boolean: ['skip-install'] });
  assert.equal(r.flags['skip-install'], true);
  assert.deepEqual(r.positionals, ['my-app']);
});

test('validateName accepts sane names and rejects traversal / injection / reserved', () => {
  assert.equal(validateName('Payments'), 'payments');
  assert.equal(validateName('users'), 'users');
  assert.equal(validateName('order-items-service'), 'order-items');
  for (const bad of ['../evil', 'a b', 'x;rm -rf /', '', '1abc', 'a_b', 'auth', 'api', 'a'.repeat(50)]) {
    assert.throws(() => validateName(bad), /Invalid|reserved/, bad);
  }
});

test('name helpers mirror Laravel Str helpers', () => {
  assert.equal(toEnvName('user-events'), 'USER_EVENTS');
  assert.equal(toStudly('user-events'), 'UserEvents');
});

test('secrets: APP_KEY format and uniqueness', () => {
  const k = appKey();
  assert.match(k, /^base64:[A-Za-z0-9+/]{43}=$/);
  assert.notEqual(secret(), secret());
  assert.equal(secret(16).length, 32);
});

test('setEnv replaces, uncomments or appends and quotes risky values', () => {
  let t = 'A=1\n# B=old\nC=\n';
  t = setEnv(t, 'A', '2');
  t = setEnv(t, 'B', 'new');
  t = setEnv(t, 'C', 'has space');
  t = setEnv(t, 'D', 'x');
  assert.equal(getEnv(t, 'A'), '2');
  assert.equal(getEnv(t, 'B'), 'new');
  assert.equal(getEnv(t, 'C'), 'has space');
  assert.equal(getEnv(t, 'D'), 'x');
});

test('compareVersions', () => {
  assert.ok(compareVersions('8.4.1', '8.3.0') > 0);
  assert.ok(compareVersions('20.11.0', '20.11') === 0);
  assert.ok(compareVersions('2.5.9', '2.6.0') < 0);
});

function fakeWorkspace() {
  const root = mkdtempSync(join(tmpdir(), 'nl-ws-'));
  const app = join(root, 'apps', 'orders-service');
  mkdirSync(join(app, 'app', 'Modules', 'Orders'), { recursive: true });
  mkdirSync(join(app, 'config'), { recursive: true });
  writeFileSync(
    join(app, 'config', 'kafka.php'),
    "<?php\nreturn [\n    'topics' => [\n        'default' => env('KAFKA_TOPIC_DEFAULT', 'orders.events'),\n        // @nestlaravel:topics\n    ],\n];\n",
  );
  writeFileSync(join(app, '.env.example'), 'APP_NAME=orders\n');
  return { root, app };
}

test('generate kafka-event writes an envelope-based event and consumer', async () => {
  const { root, app } = fakeWorkspace();
  await generateKafkaEvent(root, 'order.created', { service: 'orders', consumer: true });
  const event = readFileSync(join(app, 'app/Modules/Orders/Domain/Events/OrderCreated.php'), 'utf8');
  assert.match(event, /namespace App\\Modules\\Orders\\Domain\\Events;/);
  assert.match(event, /use NestLaravel\\Kafka\\Events\\AbstractDomainEvent;/);
  assert.match(event, /return 'order\.created';/);
  assert.match(event, /return 'order';/);
  assert.ok(existsSync(join(app, 'app/Modules/Orders/Infrastructure/Messaging/OrderCreatedHandler.php')));
  await assert.rejects(() => generateKafkaEvent(root, 'order.created', { service: 'orders' }), /already exists/);
  await assert.rejects(() => generateKafkaEvent(root, 'Order Created', { service: 'orders' }), /Invalid event type/);
  await assert.rejects(() => generateKafkaEvent(root, 'a.b', { service: '../etc' }), /Invalid service name/);
});

test('generate kafka-topic registers the topic once and adds the env var', async () => {
  const { root, app } = fakeWorkspace();
  await generateKafkaTopic(root, 'user-events', { service: 'orders' });
  await generateKafkaTopic(root, 'user-events', { service: 'orders' });
  const cfg = readFileSync(join(app, 'config/kafka.php'), 'utf8');
  assert.equal(cfg.match(/'user' =>/g).length, 1, 'suffix "-events" is dropped: aggregate_type "user" routes here');
  assert.match(cfg, /env\('KAFKA_TOPIC_USER', 'user-events'\)/);
  assert.match(readFileSync(join(app, '.env.example'), 'utf8'), /KAFKA_TOPIC_USER=user-events/);
  await assert.rejects(() => generateKafkaTopic(root, 'Bad Topic!', { service: 'orders' }), /Invalid topic/);
});

test('managed sync: replaces untouched files, never overwrites edited ones', () => {
  const root = mkdtempSync(join(tmpdir(), 'nl-managed-'));
  const tpl = mkdtempSync(join(tmpdir(), 'nl-tpl-'));
  mkdirSync(join(tpl, 'workspace/infrastructure/docker'), { recursive: true });
  const put = (rel, text) => {
    mkdirSync(join(root, rel, '..'), { recursive: true });
    writeFileSync(join(root, rel), text);
  };
  writeFileSync(join(tpl, 'workspace/infrastructure/docker/a.conf'), 'v1');
  writeFileSync(join(tpl, 'workspace/infrastructure/docker/b.conf'), 'v1');

  put('infrastructure/docker/a.conf', 'v1');
  put('infrastructure/docker/b.conf', 'v1');
  recordManaged(root, managedMap(tpl, []));

  // Release 2 changes both; the user edited b.conf.
  writeFileSync(join(tpl, 'workspace/infrastructure/docker/a.conf'), 'v2');
  writeFileSync(join(tpl, 'workspace/infrastructure/docker/b.conf'), 'v2');
  writeFileSync(join(root, 'infrastructure/docker/b.conf'), 'v1-user-edit');

  const plan = syncManaged(root, managedMap(tpl, []), { apply: true });
  assert.deepEqual(plan.replace, ['infrastructure/docker/a.conf']);
  assert.deepEqual(plan.conflicts, ['infrastructure/docker/b.conf']);
  assert.equal(readFileSync(join(root, 'infrastructure/docker/a.conf'), 'utf8'), 'v2');
  assert.equal(readFileSync(join(root, 'infrastructure/docker/b.conf'), 'utf8'), 'v1-user-edit');
  assert.equal(readFileSync(join(root, 'infrastructure/docker/b.conf.nestlaravel-new'), 'utf8'), 'v2');
  assert.equal(sha(Buffer.from('x')).length, 64);
});
