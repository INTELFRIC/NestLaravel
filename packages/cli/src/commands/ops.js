import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs } from '../args.js';
import { run } from '../exec.js';
import { c, CliError, log } from '../ui.js';
import { requireWorkspace } from '../workspace.js';

/** Commands forwarded to `php artisan` inside one or all apps: nestlaravel <name> [--service <svc>] [artisan options]. */
export const OPS_COMMANDS = {
  'events:list': 'List registered events, versions and required payload fields',
  'events:check': 'Verify schema versions are backward compatible',
  'kafka:health': 'Probe broker connectivity (exit 1 when unreachable)',
  'outbox:status': 'Outbox backlog, oldest pending age, failed rows (--failed, --requeue)',
  'dlq:list': 'Peek dead-lettered messages of a topic',
  'tenant:check': 'Audit models/tables for tenant isolation gaps',
};

/** Laravel apps of the workspace: [{ name, dir }] (gateway first). */
export function laravelApps(root) {
  const apps = join(root, 'apps');
  if (!existsSync(apps)) return [];
  return readdirSync(apps, { withFileTypes: true })
    .filter((e) => e.isDirectory() && existsSync(join(apps, e.name, 'artisan')))
    .map((e) => ({ name: e.name, dir: join(apps, e.name) }))
    .sort((a, b) => (a.name === 'api' ? -1 : b.name === 'api' ? 1 : a.name.localeCompare(b.name)));
}

/** --service payments → the apps/payments-service app; no flag → all apps. */
export function selectApps(root, service) {
  const all = laravelApps(root);
  if (!service || service === true) return all;
  const wanted = String(service).replace(/-service$/, '');
  const match = all.filter((a) => a.name === wanted || a.name === `${wanted}-service` || (wanted === 'gateway' && a.name === 'api'));
  if (match.length === 0) throw new CliError(`No app "${service}". Available: ${all.map((a) => a.name).join(', ') || 'none'}`);
  return match;
}

const hasVendor = (app) => existsSync(join(app.dir, 'vendor', 'autoload.php'));

/** Split argv into our own flags (--service, --help) and the arguments to hand to artisan unchanged. */
export function splitArgs(argv) {
  const { positionals, flags, rest } = parseArgs(argv, { boolean: ['json', 'failed', 'requeue', 'help'] });
  const passthrough = [...positionals];
  for (const [k, v] of Object.entries(flags)) {
    if (k === 'service' || k === 'help') continue;
    passthrough.push(v === false ? `--no-${k}` : v === true ? `--${k}` : `--${k}=${v}`);
  }
  passthrough.push(...rest);
  return { service: flags.service, help: Boolean(flags.help), passthrough };
}

export async function forward(command, argv) {
  const { service, help, passthrough } = splitArgs(argv);
  const root = requireWorkspace();
  if (help) {
    console.log(`Usage: nestlaravel ${command} [--service <name>] [options]\n\n  ${OPS_COMMANDS[command]}\n\nRuns "php artisan ${command}" in one app (--service) or in every app. Other options are passed through.`);
    return 0;
  }
  const apps = selectApps(root, service);

  let worst = 0;
  for (const app of apps) {
    if (!hasVendor(app)) {
      log.warn(`${app.name}: dependencies not installed (composer install) — skipped`);
      worst = Math.max(worst, 1);
      continue;
    }
    if (apps.length > 1) log.info(`\n${c.bold(app.name)}`);
    const { code, stderr } = await run('php', ['artisan', command, ...passthrough], { cwd: app.dir, allowFailure: true });
    if (code !== 0) {
      if (/not defined|no commands defined/i.test(stderr ?? '')) log.info(c.dim(`  (${command} is not available in ${app.name})`));
      else worst = Math.max(worst, code);
    }
  }
  return worst;
}

/** Merge per-app findings into one verdict. Exported for tests. */
export function verdict(findings) {
  const count = (s) => findings.filter((f) => f.status === s).length;
  const fail = count('fail');
  const warn = count('warn');
  const pass = count('pass');
  const label = fail > 0 ? 'NOT READY' : warn > 0 ? 'READY WITH WARNINGS' : 'NO FINDINGS';
  return { pass, warn, fail, label, exitCode: fail > 0 ? 1 : 0 };
}

/** Workspace-level checks that need no PHP. */
export function workspaceFindings(root) {
  const out = [];
  const gitignore = existsSync(join(root, '.gitignore')) ? readFileSync(join(root, '.gitignore'), 'utf8') : '';
  out.push(/^\.env$|^\*\*\/\.env$|^\.env\b/m.test(gitignore)
    ? { status: 'pass', message: '.env files are git-ignored' }
    : { status: 'fail', message: '.gitignore does not ignore .env — secrets could be committed' });

  const compose = join(root, 'docker-compose.yml');
  if (existsSync(compose)) {
    const text = readFileSync(compose, 'utf8');
    out.push(/stop_grace_period/.test(text)
      ? { status: 'pass', message: 'docker-compose sets stop_grace_period (graceful shutdown window)' }
      : { status: 'warn', message: 'docker-compose has no stop_grace_period: SIGTERM is followed by SIGKILL after 10s' });
  }
  out.push(existsSync(join(root, 'infrastructure', 'k8s'))
    ? { status: 'pass', message: 'Kubernetes reference manifests present (infrastructure/k8s)' }
    : { status: 'warn', message: 'No infrastructure/k8s reference manifests (ignore if you deploy differently)' });
  return out;
}

export async function productionCheck(argv) {
  const { flags } = parseArgs(argv, { boolean: ['json', 'help'] });
  const root = requireWorkspace();
  if (flags.help) {
    console.log('Usage: nestlaravel production:check [--service <name>] [--json]\n\nAudits the EFFECTIVE configuration of every app (php artisan nestlaravel:check) and the workspace.\nPrints PASS/WARN/FAIL per finding; exits 1 on any FAIL. A clean result means "no known misconfiguration",\nnot "production ready": load-, failover- and restore-testing are still yours (docs/OPERATIONS.md).');
    return 0;
  }

  const sections = [{ app: 'workspace', findings: workspaceFindings(root) }];
  for (const app of selectApps(root, flags.service)) {
    if (!hasVendor(app)) {
      sections.push({ app: app.name, findings: [{ status: 'warn', message: 'dependencies not installed — configuration was NOT checked' }] });
      continue;
    }
    const { code, stdout, stderr } = await run('php', ['artisan', 'nestlaravel:check', '--json'], { cwd: app.dir, silent: true, allowFailure: true });
    let parsed = null;
    try {
      parsed = JSON.parse(stdout.trim().split('\n').pop());
    } catch {
      /* fallthrough */
    }
    sections.push({
      app: app.name,
      findings: parsed?.findings ?? [{ status: 'fail', message: `could not run nestlaravel:check (exit ${code}): ${(stderr || stdout).trim().split('\n')[0] ?? ''}` }],
    });
  }

  const all = sections.flatMap((s) => s.findings);
  const result = verdict(all);

  if (flags.json) {
    console.log(JSON.stringify({ result: result.label, ...result, sections }, null, 2));
    return result.exitCode;
  }

  const tag = { pass: c.green('[PASS]'), warn: c.yellow('[WARN]'), fail: c.red('[FAIL]') };
  for (const s of sections) {
    log.info(`\n${c.bold(s.app)}`);
    for (const f of s.findings) log.info(`  ${tag[f.status]} ${f.message}`);
  }
  log.info(`\n${c.bold(result.label)} — ${result.pass} passed, ${result.warn} warning(s), ${result.fail} failure(s)`);
  if (result.exitCode === 0) log.info(c.dim('No known misconfiguration. This is not a certification: rehearse failover and restore (docs/OPERATIONS.md).'));
  return result.exitCode;
}
