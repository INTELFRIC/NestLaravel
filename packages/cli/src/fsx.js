import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, renameSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { CliError } from './ui.js';

const here = dirname(fileURLToPath(import.meta.url));

/** Directory holding the bundled templates (built by scripts/build-templates.mjs). */
export function templatesDir() {
  const dir = join(here, '..', 'templates');
  if (!existsSync(join(dir, '.built'))) {
    throw new CliError(
      'Bundled templates are missing. In the monorepo run "npm run build:templates" in packages/cli; ' +
        'an installed package always contains them.',
    );
  }
  return dir;
}

export const ensureDir = (dir) => mkdirSync(dir, { recursive: true });

export function copyTree(from, to, { overwrite = false } = {}) {
  ensureDir(dirname(to));
  cpSync(from, to, { recursive: true, force: overwrite, errorOnExist: false });
  if (statSync(to).isDirectory()) restoreDotfiles(to);
}

/** Templates ship `_gitignore` (npm drops real .gitignore files); restore the dot name. */
export function restoreDotfiles(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    if (entry.isDirectory()) restoreDotfiles(p);
    else if (entry.name === '_gitignore') renameSync(p, join(dir, '.gitignore'));
  }
}

/** Write a file only if it does not exist (or `overwrite`). Returns true when written. */
export function writeIfAbsent(path, content, { overwrite = false } = {}) {
  if (existsSync(path) && !overwrite) return false;
  ensureDir(dirname(path));
  writeFileSync(path, content);
  return true;
}

export const read = (path) => readFileSync(path, 'utf8');

/** Read, transform, write. Skips the write when nothing changed. Returns true if changed. */
export function editFile(path, transform) {
  const before = readFileSync(path, 'utf8');
  const after = transform(before);
  if (after === before) return false;
  writeFileSync(path, after);
  return true;
}

/** Replace {{key}} placeholders. Unknown placeholders are left untouched. */
export function render(text, vars) {
  return text.replace(/\{\{(\w+)\}\}/g, (m, key) => (key in vars ? String(vars[key]) : m));
}

/** Refuse to write outside `root` (defence against crafted names / paths). */
export function assertInside(root, target) {
  const rel = relative(resolve(root), resolve(target));
  if (rel.startsWith('..') || rel.split(sep).includes('..') || resolve(target) === resolve(root) && false) {
    throw new CliError(`Refusing to write outside the workspace: ${target}`);
  }
  return resolve(target);
}

export function listFiles(dir) {
  const out = [];
  (function walk(d) {
    for (const e of readdirSync(d, { withFileTypes: true })) {
      const p = join(d, e.name);
      if (e.isDirectory()) walk(p);
      else out.push(p);
    }
  })(dir);
  return out;
}

export const isEmptyDir = (dir) => !existsSync(dir) || (statSync(dir).isDirectory() && readdirSync(dir).length === 0);
