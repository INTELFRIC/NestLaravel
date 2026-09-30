#!/usr/bin/env node
// Validates the npm tarball BEFORE publish: contents allowlist, no secrets/local artefacts, sane size.
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const cwd = join(dirname(fileURLToPath(import.meta.url)), '..');
const raw = execSync('npm pack --dry-run --json --ignore-scripts', { cwd, encoding: 'utf8' });
const [info] = JSON.parse(raw);
const files = info.files.map((f) => f.path);

const forbidden = [
  [/(^|\/)\.env($|\.(?!example$))/, 'env file'],
  [/(^|\/)(vendor|node_modules)\//, 'dependency directory'],
  [/\.(log|sqlite|sqlite3|pem|key|p12|pfx)$/, 'log/db/key file'],
  [/(^|\/)auth\.json$/, 'composer auth.json'],
  [/(^|\/)\.git(\/|$)/, 'git metadata'],
  [/(^|\/)\.claude\//, 'assistant state'],
];
const allowedRoots = /^(bin|src|templates|README\.md|CHANGELOG\.md|LICENSE|package\.json)(\/|$)/;

const problems = [];
for (const f of files) {
  if (!allowedRoots.test(f)) problems.push(`unexpected file in package: ${f}`);
  for (const [re, label] of forbidden) if (re.test(f)) problems.push(`${label}: ${f}`);
}
for (const required of ['bin/nestlaravel.js', 'templates/.built', 'templates/workspace/apps/api/artisan', 'templates/service-template/artisan', 'README.md', 'LICENSE']) {
  if (!files.includes(required)) problems.push(`missing required file: ${required}`);
}
const MAX = 8 * 1024 * 1024;
if (info.size > MAX) problems.push(`tarball is ${(info.size / 1e6).toFixed(1)} MB (limit ${MAX / 1e6} MB)`);

if (problems.length) {
  console.error(`Package validation FAILED (${problems.length}):\n  - ${problems.slice(0, 40).join('\n  - ')}`);
  process.exit(1);
}
console.log(`Package OK: ${info.name}@${info.version}, ${files.length} files, ${(info.size / 1024).toFixed(0)} kB packed / ${(info.unpackedSize / 1024).toFixed(0)} kB unpacked`);
