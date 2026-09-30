import { copyFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { editFile, ensureDir, writeIfAbsent } from '../fsx.js';
import { CliError, log } from '../ui.js';
import { toStudly } from '../workspace.js';
import { resolveTarget } from './kafka.js';

const EVENT_TYPE = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,3}$/;
const MARKER = '// @nestlaravel:events';

const className = (type, version) => toStudly(type.split('.').join('-')) + (version > 1 ? `V${version}` : '');

/** PHP source of a schema-governed event class. Exported for tests. */
export function eventSource({ type, version, ns, cls, aggregate, service }) {
  return `<?php

namespace ${ns};

use NestLaravel\\Kafka\\Contracts\\HasEventSchema;
use NestLaravel\\Kafka\\Events\\AbstractDomainEvent;
use NestLaravel\\Kafka\\Schema\\EventSchema;

/**
 * Domain event "${type}", schema version ${version}.
 *
 * The schema below is the contract with every consumer. Published versions are immutable: to change the payload
 * incompatibly create the next version (nestlaravel generate event ${type} --service ${service} --version ${version + 1}).
 * Adding an OPTIONAL field is compatible; renaming, removing or newly requiring a field is not.
 */
final class ${cls} extends AbstractDomainEvent implements HasEventSchema
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

    public static function eventSchema(): EventSchema
    {
        return new EventSchema('${type}', ${version}, [
            'id' => 'required|string',
            // 'amount' => 'required|numeric|min:0',
            // 'note'   => 'nullable|string',
        ]);
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
        return ${version};
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['id' => $this->aggregateId] + $this->data;
    }
}
`;
}

/**
 * nestlaravel generate event <domain.entity.action> --service <svc> [--version N] [--force]
 *
 * Schema-governed event: the class declares its payload schema (Laravel validation rules) and is registered in
 * config/kafka.php `events`, so producers refuse to emit — and consumers refuse to process — an invalid payload.
 * Version 1 is `OrderCreated`; a later version is a NEW class `OrderCreatedV2` next to it (old consumers keep working).
 */
export async function generateEvent(root, rawType, flags) {
  const type = String(rawType ?? '').trim();
  if (!EVENT_TYPE.test(type)) {
    throw new CliError(`Invalid event type "${rawType}". Use dotted lowercase, e.g. "order.created" or "billing.invoice.paid".`);
  }
  const version = flags.version === undefined || flags.version === true ? 1 : Number(flags.version);
  if (!Number.isInteger(version) || version < 1 || version > 99) {
    throw new CliError(`Invalid --version "${flags.version}". Use a positive integer (1, 2, ...).`);
  }
  const target = resolveTarget(root, flags.service);
  if (target.isGateway) {
    throw new CliError('Schema-governed events are generated for services. Use "generate kafka-event" for the gateway, or pick a service with --service.');
  }

  const parts = type.split('.');
  const cls = className(type, version);
  const aggregate = parts.length > 2 ? parts[parts.length - 2] : parts[0];
  const moduleDir = join(target.appDir, 'app', 'Modules', target.module);
  if (!existsSync(moduleDir)) {
    throw new CliError(`Module directory not found: apps/${target.dir}/app/Modules/${target.module}`);
  }

  const eventsDir = join(moduleDir, 'Domain', 'Events');
  ensureDir(eventsDir);
  if (version > 1 && !existsSync(join(eventsDir, `${className(type, version - 1)}.php`))) {
    log.warn(`No class for version ${version - 1} of "${type}" found. Create versions in order so compatibility can be checked (php artisan events:check).`);
  }

  const ns = `App\\Modules\\${target.module}\\Domain\\Events`;
  const eventFile = join(eventsDir, `${cls}.php`);
  if (existsSync(eventFile) && !flags.force) throw new CliError(`${cls} already exists (use --force).`);

  writeIfAbsent(eventFile, eventSource({ type, version, ns, cls, aggregate, service: target.name }), { overwrite: Boolean(flags.force) });
  log.ok(`Event    apps/${target.dir}/app/Modules/${target.module}/Domain/Events/${cls}.php  (${type} v${version})`);

  // Register in config/kafka.php (publish the kit config first — services otherwise use the kit's defaults).
  const configPath = join(target.appDir, 'config', 'kafka.php');
  if (!existsSync(configPath)) {
    const kit = join(root, 'packages', 'laravel-kafka', 'config', 'kafka.php');
    if (!existsSync(kit)) throw new CliError(`apps/${target.dir}/config/kafka.php not found.`);
    ensureDir(join(target.appDir, 'config'));
    copyFileSync(kit, configPath);
    log.info(`Published the Kafka kit config to apps/${target.dir}/config/kafka.php`);
  }
  const ref = `\\${ns}\\${cls}::class`;
  const registered = editFile(configPath, (text) => {
    if (text.includes(ref)) return text;
    if (!text.includes(MARKER)) {
      throw new CliError(`config/kafka.php has no "${MARKER}" marker inside 'events' => [ ]. Add ${ref}, to it manually.`);
    }
    return text.replace(`        ${MARKER}`, `        ${ref},\n        ${MARKER}`);
  });
  if (registered) log.ok(`Registered in apps/${target.dir}/config/kafka.php → 'events'`);

  log.blank();
  log.info('Next: fill in the schema, then verify compatibility across versions:');
  log.cmd(`cd apps/${target.dir} && php artisan events:check`);
}
