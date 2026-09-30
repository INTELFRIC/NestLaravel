import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { CliError } from './ui.js';
import { MANIFEST } from './versions.js';

/** Walk up from `start` looking for nestlaravel.json. */
export function findWorkspace(start = process.cwd()) {
  let dir = resolve(start);
  for (;;) {
    if (existsSync(join(dir, MANIFEST))) return dir;
    const parent = dirname(dir);
    if (parent === dir) return null;
    dir = parent;
  }
}

export function requireWorkspace(start) {
  const root = findWorkspace(start);
  if (!root) {
    throw new CliError(
      `Not inside a NestLaravel workspace (no ${MANIFEST} found).\n` +
        '  Create one with: npx nestlaravel create <name>\n' +
        '  Or adopt an existing project: npx nestlaravel update',
    );
  }
  return root;
}

export const readManifest = (root) => JSON.parse(readFileSync(join(root, MANIFEST), 'utf8'));

export function writeManifest(root, manifest) {
  writeFileSync(join(root, MANIFEST), `${JSON.stringify(manifest, null, 2)}\n`);
}

/** "user-events" → "USER_EVENTS" (mirrors Str::upper(Str::snake(Str::studly()))). */
export const toEnvName = (name) => name.replace(/-/g, '_').toUpperCase();

/** "user-events" → "UserEvents". */
export const toStudly = (name) =>
  name
    .split(/[-_.\s]+/)
    .filter(Boolean)
    .map((p) => p[0].toUpperCase() + p.slice(1))
    .join('');

const RESERVED = new Set(['auth', 'api', 'gateway', 'platform', 'admin', 'customer', 'kafka', 'nx', 'test', 'tests']);

/** Validate a service/project name; returns the normalised kebab-case name. */
export function validateName(input, { kind = 'name', allowReserved = false } = {}) {
  const name = String(input ?? '').trim().toLowerCase().replace(/-service$/, '');
  if (!/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/.test(name) || name.length > 40) {
    throw new CliError(`Invalid ${kind} "${input}". Use lowercase letters, digits and dashes, starting with a letter (e.g. "payments").`);
  }
  if (!allowReserved && RESERVED.has(name)) {
    throw new CliError(`"${name}" is reserved (the gateway owns it). Pick a business domain name such as "orders" or "inventory".`);
  }
  return name;
}
