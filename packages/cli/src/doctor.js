import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { capture } from './exec.js';
import { RUNTIME } from './versions.js';
import { c, log } from './ui.js';

/** Compare dotted versions numerically. Returns <0, 0, >0. */
export function compareVersions(a, b) {
  const pa = String(a).split('.').map((n) => parseInt(n, 10) || 0);
  const pb = String(b).split('.').map((n) => parseInt(n, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const diff = (pa[i] ?? 0) - (pb[i] ?? 0);
    if (diff !== 0) return diff;
  }
  return 0;
}

const firstVersion = (text) => text?.match(/(\d+\.\d+(?:\.\d+)?)/)?.[1] ?? null;

/**
 * Inspect the machine. Every check returns { name, ok, required, version, message }.
 * `required: false` checks only warn.
 */
export function checkRequirements({ needDocker = false, db = null } = {}) {
  const results = [];
  const add = (name, { version, min, required = true, missingHint, extra }) => {
    const found = version != null;
    const okVersion = found && (!min || compareVersions(version, min) >= 0);
    results.push({
      name,
      ok: found && okVersion && (extra?.ok ?? true),
      required,
      version,
      message: !found
        ? `not found. ${missingHint ?? ''}`.trim()
        : !okVersion
          ? `${version} is too old (need >= ${min}). ${missingHint ?? ''}`.trim()
          : (extra?.message ?? `${version}`),
    });
  };

  add('Node.js', { version: process.versions.node, min: RUNTIME.node.min, missingHint: 'Install from https://nodejs.org' });
  add('npm', { version: firstVersion(capture('npm', ['--version'])), min: RUNTIME.npm.min, missingHint: 'Ships with Node.js.' });

  const phpOut = capture('php', ['-r', 'echo PHP_VERSION;']);
  const phpVersion = firstVersion(phpOut);
  let extra;
  if (phpVersion) {
    const loaded = (capture('php', ['-m']) ?? '').toLowerCase().split(/\r?\n/);
    const missing = RUNTIME.phpExtensions.filter((ext) => !loaded.includes(ext));
    extra = missing.length
      ? { ok: false, message: `missing PHP extensions: ${missing.join(', ')}` }
      : { ok: true, message: phpVersion };
  }
  add('PHP', { version: phpVersion, min: RUNTIME.php.min, missingHint: 'Install PHP 8.3+ (https://www.php.net/downloads).', extra });

  // The PDO driver for the chosen --db. Without it `artisan migrate` dies with "could not find driver".
  if (db && phpVersion) {
    const driver = `pdo_${db}`;
    const loaded = (capture('php', ['-m']) ?? '').toLowerCase().split(/\r?\n/).includes(driver);
    const ini = (capture('php', ['--ini']) ?? '').match(/Loaded Configuration File:\s*(.+)/)?.[1]?.trim();
    const iniHint = ini && ini !== '(none)' ? ini : 'php.ini (see "php --ini")';
    results.push({
      name: `PHP ${db}`,
      ok: loaded,
      required: true,
      version: loaded ? 'loaded' : null,
      message: loaded
        ? `${driver} loaded`
        : `${driver} extension not loaded (needed for --db ${db}). ` +
          (process.platform === 'win32'
            ? `Uncomment "extension=${driver}"${db === 'pgsql' ? ' and "extension=pgsql"' : ''} in ${iniHint}.`
            : `Install it (e.g. apt install php-${db === 'pgsql' ? 'pgsql' : db}) or choose another --db.`),
    });
  }

  add('Composer', {
    version: firstVersion(capture('composer', ['--version', '--no-ansi'])),
    min: RUNTIME.composer.min,
    missingHint: 'Install from https://getcomposer.org',
  });

  add('Git', { version: firstVersion(capture('git', ['--version'])), required: false, missingHint: 'Optional; used for `git init`.' });

  const docker = firstVersion(capture('docker', ['--version']));
  const daemon = docker ? capture('docker', ['info', '--format', '{{.ServerVersion}}'], { timeout: 8000 }) : null;
  add('Docker', {
    version: docker,
    required: needDocker,
    missingHint: 'Needed for Kafka/Postgres/Redis dev infrastructure and image builds.',
    extra: docker ? { ok: daemon != null, message: daemon ? `${docker} (daemon ${daemon})` : `${docker} — daemon not running` } : undefined,
  });

  const rdkafka = (capture('php', ['-m']) ?? '').toLowerCase().split(/\r?\n/).includes('rdkafka');
  results.push({
    name: 'php-rdkafka',
    ok: rdkafka,
    required: false,
    version: rdkafka ? 'loaded' : null,
    message: rdkafka
      ? 'loaded'
      : 'not loaded — apps fall back to the log/null Kafka driver on the host (Docker images include it).',
  });

  const modules = (capture('php', ['-m']) ?? '').toLowerCase().split(/\r?\n/);
  const signals = ['pcntl', 'posix'].filter((m) => !modules.includes(m));
  results.push({
    name: 'php-pcntl',
    ok: signals.length === 0,
    required: false,
    version: signals.length === 0 ? 'loaded' : null,
    message: signals.length === 0
      ? 'pcntl + posix loaded (graceful SIGTERM shutdown of consumers/outbox workers)'
      : `${signals.join(', ')} not loaded — workers cannot shut down gracefully on this machine (Linux containers include them).`,
  });

  return results;
}

/**
 * Inspect a workspace's apps (only when run inside one). Returns [{ name, ok, required:false, message }].
 * Static checks only — `nestlaravel production:check` audits the effective configuration.
 */
export function checkWorkspace(root) {
  const results = [];
  const apps = join(root, 'apps');
  if (!existsSync(apps)) return results;

  for (const entry of readdirSync(apps, { withFileTypes: true })) {
    const dir = join(apps, entry.name);
    if (!entry.isDirectory() || !existsSync(join(dir, 'artisan'))) continue;

    const problems = [];
    if (!existsSync(join(dir, 'vendor', 'autoload.php'))) problems.push('composer dependencies not installed');
    const envFile = join(dir, '.env');
    if (!existsSync(envFile)) {
      problems.push('.env missing (copy .env.example)');
    } else {
      const env = Object.fromEntries(
        readFileSync(envFile, 'utf8')
          .split(/\r?\n/)
          .map((l) => l.match(/^([A-Z0-9_]+)=(.*)$/))
          .filter(Boolean)
          .map((m) => [m[1], m[2].replace(/^["']|["']$/g, '')]),
      );
      if (!env.APP_KEY) problems.push('APP_KEY empty');
      if (entry.name !== 'api' && (env.INTERNAL_SERVICE_SECRET ?? '').length < 32) problems.push('INTERNAL_SERVICE_SECRET missing or shorter than 32 chars');
      if (env.APP_DEBUG === 'true' && env.APP_ENV === 'production') problems.push('APP_DEBUG=true in production');
    }
    results.push({ name: entry.name, ok: problems.length === 0, required: false, message: problems.length ? problems.join('; ') : 'env, secrets and dependencies look sane' });
  }
  return results;
}

export function printRequirements(results) {
  for (const r of results) {
    const mark = r.ok ? c.green('✔') : r.required ? c.red('✖') : c.yellow('!');
    log.info(`  ${mark} ${r.name.padEnd(12)} ${r.message}`);
  }
  return results.filter((r) => r.required && !r.ok);
}
