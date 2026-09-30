#!/usr/bin/env node
// Assembles packages/cli/templates/ from the monorepo sources (single source of truth).
// Runs on `npm pack` / `npm publish` (prepack). Uses an explicit ALLOWLIST of roots and a
// DENYLIST of local/secret artefacts, then scans the result for secrets and fails on any hit.
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const cliRoot = join(here, '..');
const repoRoot = join(cliRoot, '..', '..');
const out = join(cliRoot, 'templates');

const DENY_NAMES = new Set([
  'vendor', 'node_modules', '.git', '.next', '.nx', '.idea', '.vscode', '.phpunit.cache',
  '.phpunit.result.cache', 'project.json.bak', 'Thumbs.db', '.DS_Store', 'out', 'dist', 'coverage',
]);
const DENY_EXT = new Set(['.log', '.sqlite', '.sqlite3', '.pem', '.key', '.p12', '.pfx']);

function allowed(path, isDir) {
  const name = path.split(sep).pop();
  if (DENY_NAMES.has(name)) return false;
  if (name === '.env' || (name.startsWith('.env.') && name !== '.env.example')) return false;
  if (!isDir && DENY_EXT.has(name.slice(name.lastIndexOf('.')))) return false;
  const rel = relative(repoRoot, path).split(sep).join('/');
  // Laravel runtime state: keep only the .gitignore placeholders.
  if (/(^|\/)storage\//.test(rel) && !isDir && name !== '.gitignore') return false;
  if (/(^|\/)bootstrap\/cache\//.test(rel) && !isDir && name !== '.gitignore') return false;
  return true;
}

function copy(fromRel, toRel, { skip = [] } = {}) {
  const from = join(repoRoot, fromRel);
  if (!existsSync(from)) throw new Error(`template source missing: ${fromRel}`);
  cpSync(from, join(out, toRel), {
    recursive: true,
    filter: (src) => {
      const rel = relative(from, src).split(sep).join('/');
      if (skip.includes(rel)) return false;
      return allowed(src, statSync(src).isDirectory());
    },
  });
}

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

// --- workspace (what `create` scaffolds) ------------------------------------------------------
for (const f of ['docker-compose.yml', 'docker-compose.infra.yml', 'docker-compose.dev.yml', 'docker-compose.test.yml', '.dockerignore', '.env.example', '.gitattributes']) {
  copy(f, `workspace/${f}`);
}
copy('infrastructure', 'workspace/infrastructure');
copy('apps/api', 'workspace/apps/api', { skip: ['.ai'] });
copy('docs', 'workspace/docs');
// In a generated project the framework guides live in docs/framework/, not one level up.
for (const f of readdirSync(join(out, 'workspace/docs'))) {
  const p = join(out, 'workspace/docs', f);
  if (f.endsWith('.md')) writeFileSync(p, readFileSync(p, 'utf8').replaceAll('](../', '](framework/'));
}
for (const f of ['ARCHITECTURE.md', 'CLI.md', 'KAFKA.md', 'MICROSERVICES.md', 'SECURITY.md', 'DEPLOYMENT.md', 'UPGRADING.md', 'MULTI-TENANCY.md', 'INSTALLATION.md', 'FRONTEND.md', 'RELIABILITY.md', 'RELIABILITY-AUDIT.md', 'SAGA.md', 'OBSERVABILITY.md', 'OPERATIONS.md', 'DISASTER-RECOVERY.md', 'FAILURE-SCENARIOS.md', 'REFERENCE-APP.md', 'BENCHMARKS.md']) {
  copy(f, `workspace/docs/framework/${f}`);
}
copy('packages/laravel-kafka', 'workspace/packages/laravel-kafka', { skip: ['composer.lock', 'tests', 'benchmarks'] });

// --- service template (used by `generate service` → make:microservice) -------------------------
copy('apps/orders-service', 'service-template', { skip: ['project.json', 'README.md'] });

// --- optional packages / add-ons -----------------------------------------------------------------
copy('packages/laravel-tenancy', 'optional/laravel-tenancy', { skip: ['composer.lock'] });
copy('apps/customer-portal', 'optional/portals/apps/customer-portal');
copy('apps/admin-portal', 'optional/portals/apps/admin-portal');
copy('packages/api-client', 'optional/portals/packages/api-client');

// --- project files owned by the CLI (rendered at create time) -------------------------------------
copy('packages/cli/project-files', 'project-files');

// npm never publishes files named .gitignore (it silently drops them), and Laravel needs the
// storage/** placeholders. Ship them as `_gitignore`; the CLI restores the dot name on copy.
(function renameGitignores(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    if (entry.isDirectory()) renameGitignores(p);
    else if (entry.name === '.gitignore') renameSync(p, join(dir, '_gitignore'));
  }
})(out);

// --- secret scan: fail the build/publish on anything that looks like a credential ---------------
const PATTERNS = [
  [/-----BEGIN [A-Z ]*PRIVATE KEY-----/, 'private key'],
  [/AKIA[0-9A-Z]{16}/, 'AWS access key id'],
  [/^(APP_KEY|JWT_SECRET)=.+$/m, 'non-empty APP_KEY/JWT_SECRET'],
  [(text) => envSecretLines(text).length > 0, 'non-empty password/secret/token in an env file'],
  [/ghp_[A-Za-z0-9]{30,}|npm_[A-Za-z0-9]{30,}|xox[baprs]-[A-Za-z0-9-]{10,}/, 'API token'],
];
const SENSITIVE = /^([A-Z0-9_]*(PASSWORD|SECRET|TOKEN|API_KEY|ACCESS_KEY)[A-Z0-9_]*)=(.*)$/;
const SAFE_VALUE = /^(|null|false|true|"?"?|'?'?|\$\{[^}]*\}|change-me|your-.*)$/i;
function envSecretLines(text) {
  return text.split(/\r?\n/).filter((line) => {
    const m = line.match(SENSITIVE);
    return m && !line.trimStart().startsWith('#') && !SAFE_VALUE.test(m[3].trim());
  });
}
const findings = [];
(function walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, entry.name);
    if (entry.isDirectory()) { walk(p); continue; }
    if (statSync(p).size > 2_000_000) continue;
    let text;
    try { text = readFileSync(p, 'utf8'); } catch { continue; }
    const isEnvLike = /\.env(\.|$)|\.example$/.test(entry.name);
    for (const [re, label] of PATTERNS) {
      if (label.includes('env file') && !isEnvLike) continue;
      const hit = typeof re === 'function' ? re(text) : re.test(text);
      if (hit) findings.push(`${relative(out, p)}: ${label}`);
    }
  }
})(out);

if (findings.length) {
  console.error('Secret scan FAILED — refusing to build templates:\n  ' + findings.join('\n  '));
  rmSync(out, { recursive: true, force: true });
  process.exit(1);
}

writeFileSync(join(out, '.built'), `${new Date().toISOString()}\n`);
console.log(`templates built → ${relative(process.cwd(), out) || out}`);
