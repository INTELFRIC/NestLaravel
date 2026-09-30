import { copyFileSync, existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { editFile, ensureDir, writeIfAbsent } from '../fsx.js';
import { run } from '../exec.js';
import { c, CliError, log } from '../ui.js';
import { toEnvName, toStudly, validateName } from '../workspace.js';

const EVENT_TYPE = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,3}$/;

/** Resolve the target Laravel app + the namespace/module conventions it uses. */
export function resolveTarget(root, service) {
  if (!service) throw new CliError('Pass the target with --service <name> (use "api" for the gateway).');
  const isGateway = service === 'api' || service === 'gateway';
  const name = isGateway ? 'api' : validateName(service, { kind: 'service name' });
  const dir = isGateway ? 'api' : `${name}-service`;
  const appDir = join(root, 'apps', dir);
  if (!existsSync(appDir)) throw new CliError(`apps/${dir} does not exist.`);
  return {
    name,
    dir,
    appDir,
    isGateway,
    // The gateway keeps its own copy of the kit under App\\Messaging; services use nestlaravel/kafka.
    baseClass: isGateway ? 'App\\Messaging\\Events\\AbstractDomainEvent' : 'NestLaravel\\Kafka\\Events\\AbstractDomainEvent',
    handlerContract: isGateway ? 'App\\Messaging\\Consumers\\MessageHandler' : 'NestLaravel\\Kafka\\Consumers\\MessageHandler',
    module: isGateway ? 'Users' : toStudly(name),
  };
}

/**
 * nestlaravel generate kafka-event <domain.entity.action> --service <svc> [--consumer]
 * Creates a domain event following the standard envelope (event_id, event_type, version,
 * occurred_at, source, correlation_id, payload) and optionally a consumer handler.
 */
export async function generateKafkaEvent(root, rawType, flags) {
  const type = String(rawType ?? '').trim();
  if (!EVENT_TYPE.test(type)) {
    throw new CliError(`Invalid event type "${rawType}". Use dotted lowercase, e.g. "user.created" or "billing.invoice.paid".`);
  }
  const target = resolveTarget(root, flags.service);

  const parts = type.split('.');
  const className = toStudly(parts.join('-'));
  const aggregate = parts.length > 2 ? parts[parts.length - 2] : parts[0];
  const moduleDir = join(target.appDir, 'app', 'Modules', target.module);
  if (!existsSync(moduleDir)) {
    throw new CliError(`Module directory not found: apps/${target.dir}/app/Modules/${target.module}`);
  }

  const eventsDir = join(moduleDir, 'Domain', 'Events');
  ensureDir(eventsDir);
  const ns = `App\\Modules\\${target.module}\\Domain\\Events`;
  const eventFile = join(eventsDir, `${className}.php`);
  if (existsSync(eventFile) && !flags.force) throw new CliError(`${className} already exists (use --force).`);

  writeIfAbsent(
    eventFile,
    `<?php

namespace ${ns};

use ${target.baseClass};

/**
 * Domain event "${type}" (schema version 1).
 *
 * Breaking payload change? Override version() and bump it, and raise the consumers'
 * KAFKA_EVENT_MAX_VERSION only after they can handle the new schema.
 */
final class ${className} extends AbstractDomainEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly string $aggregateId,
        private readonly array $data = [],
        ?string $eventId = null,
        ?string $occurredAt = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ) {
        parent::__construct($eventId, $occurredAt, $correlationId, $causationId);
    }

    public function eventType(): string
    {
        return '${type}';
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function aggregateType(): string
    {
        return '${aggregate}';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['id' => $this->aggregateId] + $this->data;
    }
}
`,
    { overwrite: Boolean(flags.force) },
  );
  log.ok(`Event    apps/${target.dir}/app/Modules/${target.module}/Domain/Events/${className}.php`);

  if (flags.consumer) {
    const handlersDir = join(moduleDir, 'Infrastructure', 'Messaging');
    ensureDir(handlersDir);
    const handlerName = `${className}Handler`;
    writeIfAbsent(
      join(handlersDir, `${handlerName}.php`),
      `<?php

namespace App\\Modules\\${target.module}\\Infrastructure\\Messaging;

use ${target.handlerContract};

/**
 * Handles "${type}". Runs inside the consumer pipeline, which already provides:
 * schema-version check, idempotency (duplicate deliveries are skipped), retries with
 * backoff and dead-lettering — so this class only needs the business reaction.
 * Keep it idempotent anyway (side effects must tolerate a replay after a crash).
 *
 *   php artisan kafka:consume <topic> "App\\\\Modules\\\\${target.module}\\\\Infrastructure\\\\Messaging\\\\${handlerName}"
 */
final class ${handlerName} implements MessageHandler
{
    /**
     * @param  array<string, mixed>  $event  The standard event envelope.
     */
    public function handle(array $event): void
    {
        if (($event['event_type'] ?? null) !== '${type}') {
            return; // other event types on the same topic
        }

        $payload = $event['payload'];

        // TODO: react to the event.
    }
}
`,
      { overwrite: Boolean(flags.force) },
    );
    log.ok(`Consumer apps/${target.dir}/app/Modules/${target.module}/Infrastructure/Messaging/${handlerName}.php`);
  }

  log.blank();
  log.info('Publish it from an Action (inside the DB transaction, so the outbox stays consistent):');
  log.info(c.dim(`  app(\\${target.isGateway ? 'App\\Core\\Contracts\\EventBus' : 'NestLaravel\\Kafka\\Contracts\\EventBus'}::class)->publish(new \\${ns}\\${className}($id));`));
}

/**
 * nestlaravel generate kafka-topic <name> --service <svc> [--create]
 * Registers a topic in config/kafka.php (+ .env.example) and optionally creates it on the dev broker.
 */
export async function generateKafkaTopic(root, rawName, flags) {
  const topic = String(rawName ?? '').trim();
  if (!/^[a-z][a-z0-9._-]{1,120}$/.test(topic)) {
    throw new CliError(`Invalid topic name "${rawName}". Use lowercase letters, digits, ".", "_" or "-" (e.g. "user-events").`);
  }
  const target = resolveTarget(root, flags.service);
  const key = topic.replace(/[-.]events?$/, '').replace(/[-.]/g, '_');
  const envKey = `KAFKA_TOPIC_${toEnvName(key)}`;

  const configPath = join(target.appDir, 'config', 'kafka.php');
  if (!existsSync(configPath)) {
    // Services use the shared kit's defaults via mergeConfigFrom; publish them once so topics can be edited.
    const kit = join(root, 'packages', 'laravel-kafka', 'config', 'kafka.php');
    if (target.isGateway || !existsSync(kit)) throw new CliError(`apps/${target.dir}/config/kafka.php not found.`);
    ensureDir(join(target.appDir, 'config'));
    copyFileSync(kit, configPath);
    log.info(`Published the Kafka kit config to apps/${target.dir}/config/kafka.php`);
  }

  const changed = editFile(configPath, (text) => {
    if (text.includes(`'${key}' =>`)) return text;
    const line = `        '${key}' => env('${envKey}', '${topic}'),`;
    if (text.includes('// @nestlaravel:topics')) return text.replace('        // @nestlaravel:topics', `${line}\n        // @nestlaravel:topics`);
    // Gateway config has no marker: append inside the topics array.
    return text.replace(/('topics' => \[[\s\S]*?)(\n    \],)/, `$1\n${line}$2`);
  });
  const envExample = join(target.appDir, '.env.example');
  if (existsSync(envExample)) {
    editFile(envExample, (t) => (t.includes(`${envKey}=`) ? t : `${t.replace(/\s*$/, '')}\n${envKey}=${topic}\n`));
  }

  if (changed) log.ok(`Topic "${topic}" registered → events with aggregate_type "${key}" are routed to it`);
  else log.info(`Topic key "${key}" already registered in apps/${target.dir}/config/kafka.php`);

  if (flags.create) {
    log.step('Creating the topic on the dev broker (docker compose)');
    await run(
      'docker',
      ['compose', '-f', 'docker-compose.infra.yml', 'exec', '-T', 'kafka', '/opt/kafka/bin/kafka-topics.sh',
        '--bootstrap-server', 'localhost:29092', '--create', '--if-not-exists', '--topic', topic,
        '--partitions', String(flags.partitions ?? 3), '--replication-factor', '1'],
      { cwd: root },
    );
    await run(
      'docker',
      ['compose', '-f', 'docker-compose.infra.yml', 'exec', '-T', 'kafka', '/opt/kafka/bin/kafka-topics.sh',
        '--bootstrap-server', 'localhost:29092', '--create', '--if-not-exists', '--topic', `${topic}.dlq`,
        '--partitions', '1', '--replication-factor', '1'],
      { cwd: root },
    );
    log.ok(`Created "${topic}" and "${topic}.dlq"`);
  } else {
    log.info(c.dim('Tip: add --create to also create it (and its .dlq) on the dev broker.'));
  }
  return readFileSync(configPath, 'utf8').length;
}
