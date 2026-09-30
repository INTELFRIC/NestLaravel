import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { ensureDir, listFiles } from './fsx.js';

/**
 * "Managed" files are framework-owned code that ships with NestLaravel (gateway signing, Kafka kit,
 * Docker build files). `update` may replace them ONLY while they are byte-identical to what a previous
 * release wrote (tracked by hash). If you edited one, update leaves it alone and writes the new version
 * next to it as `<file>.nestlaravel-new` for you to merge. User code is never touched.
 */
const STATE = join('.nestlaravel', 'managed.json');

const posix = (p) => p.split(sep).join('/');
export const sha = (buf) => createHash('sha256').update(buf).digest('hex');

/** @returns {{from: string, to: string}[]} absolute template path → workspace-relative destination */
export function managedMap(tplDir, services) {
  const map = [];
  const addTree = (tplRel, destRel) => {
    const base = join(tplDir, tplRel);
    if (!existsSync(base)) return;
    for (const file of listFiles(base)) {
      map.push({ from: file, to: posix(join(destRel, relative(base, file))) });
    }
  };

  addTree('workspace/apps/api/app/Infrastructure/Gateway', 'apps/api/app/Infrastructure/Gateway');
  addTree('workspace/apps/api/app/Infrastructure/Kafka', 'apps/api/app/Infrastructure/Kafka');
  addTree('workspace/apps/api/app/Messaging', 'apps/api/app/Messaging');
  for (const dir of ['src', 'config', 'database']) {
    addTree(`workspace/packages/laravel-kafka/${dir}`, `packages/laravel-kafka/${dir}`);
  }
  addTree('workspace/infrastructure/docker', 'infrastructure/docker');
  addTree('workspace/infrastructure/scripts', 'infrastructure/scripts');

  for (const service of services) {
    for (const rel of ['app/Http/Middleware/VerifyGatewaySignature.php', 'config/internal.php']) {
      const from = join(tplDir, 'service-template', rel);
      if (existsSync(from)) map.push({ from, to: `apps/${service}-service/${rel}` });
    }
  }
  return map;
}

export function readState(root) {
  const file = join(root, STATE);
  return existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : {};
}

export function writeState(root, state) {
  const file = join(root, STATE);
  ensureDir(dirname(file));
  writeFileSync(file, `${JSON.stringify(state, null, 2)}\n`);
}

/** Record the hashes of managed files as they currently exist in the workspace. */
export function recordManaged(root, map) {
  const state = readState(root);
  for (const { to } of map) {
    const abs = join(root, to);
    if (existsSync(abs)) state[to] = sha(readFileSync(abs));
  }
  writeState(root, state);
}

/**
 * Plan (and optionally apply) the sync of managed files.
 * Returns { add, replace, conflicts, same }.
 */
export function syncManaged(root, map, { apply = false } = {}) {
  const state = readState(root);
  const result = { add: [], replace: [], conflicts: [], same: [] };

  for (const { from, to } of map) {
    const abs = join(root, to);
    const next = readFileSync(from);

    if (!existsSync(abs)) {
      result.add.push(to);
      if (apply) {
        ensureDir(dirname(abs));
        writeFileSync(abs, next);
        state[to] = sha(next);
      }
      continue;
    }

    const current = readFileSync(abs);
    if (sha(current) === sha(next)) {
      result.same.push(to);
      state[to] = sha(next);
      continue;
    }

    if (state[to] && state[to] === sha(current)) {
      result.replace.push(to);
      if (apply) {
        writeFileSync(abs, next);
        state[to] = sha(next);
      }
    } else {
      result.conflicts.push(to);
      if (apply) writeFileSync(`${abs}.nestlaravel-new`, next);
    }
  }

  if (apply) writeState(root, state);
  return result;
}
