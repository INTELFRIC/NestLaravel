import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs } from '../args.js';
import { capture, nx, run } from '../exec.js';
import { c, CliError, log } from '../ui.js';
import { readManifest, requireWorkspace } from '../workspace.js';

/** Projects (name, tags, directory) that define `target`, in declaration order (reads project.json, no Nx needed). */
export function projectsWithDetails(root, target) {
  const found = [];
  const apps = join(root, 'apps');
  if (!existsSync(apps)) return found;
  for (const entry of readdirSync(apps, { withFileTypes: true })) {
    const file = join(apps, entry.name, 'project.json');
    if (!entry.isDirectory() || !existsSync(file)) continue;
    try {
      const json = JSON.parse(readFileSync(file, 'utf8'));
      if (json.targets?.[target]) found.push({ name: json.name ?? entry.name, tags: json.tags ?? [], dir: join(apps, entry.name) });
    } catch {
      /* ignore malformed project.json — Nx will report it */
    }
  }
  return found;
}

const projectsWith = (root, target) => projectsWithDetails(root, target).map((p) => p.name);

/**
 * Which `serve` projects `dev` should start. Web/mobile clients need their own toolchain, so a client that cannot
 * run yet is skipped with the exact fix instead of crashing the whole `nx run-many`.
 */
export function planServe(root, { hasFlutter = () => capture('flutter', ['--version']) != null } = {}) {
  const serve = [];
  const skipped = [];
  let flutterOk;
  for (const p of projectsWithDetails(root, 'serve')) {
    if (p.tags.includes('stack:flutter')) {
      flutterOk ??= hasFlutter();
      if (!flutterOk) { skipped.push({ name: p.name, reason: 'Flutter SDK not found (https://docs.flutter.dev/get-started/install)' }); continue; }
    }
    if (p.tags.includes('stack:next') && !existsSync(join(root, 'node_modules', 'next')) && !existsSync(join(p.dir, 'node_modules', 'next'))) {
      skipped.push({ name: p.name, reason: 'dependencies missing — run: npm install' });
      continue;
    }
    serve.push(p.name);
  }
  return { serve, skipped };
}

/** Clients the manifest says were scaffolded but whose folder is gone. */
export function missingClients(root) {
  try {
    return (readManifest(root).clients ?? []).filter((x) => !existsSync(join(root, 'apps', x.name))).map((x) => x.name);
  } catch {
    return [];
  }
}

function assertInstalled(root) {
  if (!existsSync(join(root, 'node_modules', '.bin'))) {
    throw new CliError('Node dependencies are not installed. Run: npm install');
  }
}

async function runTarget(target, argv, { defaultArgs = [] } = {}) {
  const { flags, rest } = parseArgs(argv, { boolean: ['affected', 'skip-nx-cache'] });
  const root = requireWorkspace();
  assertInstalled(root);

  const projects = projectsWith(root, target);
  if (projects.length === 0) throw new CliError(`No project defines a "${target}" target.`);

  const args = flags.affected
    ? ['affected', '-t', target]
    : ['run-many', '-t', target];
  if (flags.project) args.push('-p', String(flags.project));
  if (flags['skip-nx-cache']) args.push('--skip-nx-cache');
  args.push(...defaultArgs, ...rest);

  log.step(`nx ${args.join(' ')}`);
  await nx(args, { cwd: root });
}

export const test = (argv) => runTarget('test', argv);
export const lint = (argv) => runTarget('lint', argv);

export async function build(argv) {
  const { flags } = parseArgs(argv, { boolean: ['affected'] });
  // Image naming for infrastructure/scripts/docker-build.mjs
  if (flags.tag) process.env.IMAGE_TAG = String(flags.tag);
  if (flags.registry) process.env.REGISTRY = String(flags.registry);
  if (!capture('docker', ['--version'])) {
    throw new CliError('Docker is required to build production images (https://docs.docker.com/get-docker/).');
  }
  const stripped = argv.filter((a, i, all) => !/^--(tag|registry)(=|$)/.test(a) && !/^--(tag|registry)$/.test(all[i - 1] ?? ''));
  await runTarget('build', stripped);
}

/**
 * nestlaravel dev — start shared infrastructure (Kafka/Postgres/Redis in Docker) and serve every app.
 */
export async function dev(argv) {
  const { flags } = parseArgs(argv, { boolean: ['infra', 'docker', 'help'] });
  const root = requireWorkspace();

  // Full containerised stack (real Kafka via ext-rdkafka inside the images).
  if (flags.docker) {
    log.step('Starting the full stack in Docker (gateway + services + infra)');
    return void (await run('docker', ['compose', 'up', '--build'], { cwd: root }));
  }

  assertInstalled(root);

  const wantInfra = flags.infra !== false;
  if (wantInfra) {
    if (!capture('docker', ['--version'])) {
      log.warn('Docker not found — skipping Kafka/Postgres/Redis. Apps run with the log/null Kafka driver. (Use --no-infra to silence this.)');
    } else if (!capture('docker', ['info', '--format', '{{.ServerVersion}}'], { timeout: 8000 })) {
      log.warn('Docker daemon is not running — skipping infrastructure. Start Docker Desktop or use --no-infra.');
    } else {
      if (!existsSync(join(root, '.env'))) {
        throw new CliError('Missing .env in the workspace root (holds POSTGRES_PASSWORD / REDIS_PASSWORD). Re-create it from .env.example.');
      }
      log.step('Starting infrastructure (Postgres, Redis, Kafka KRaft)');
      await run('docker', ['compose', '-f', 'docker-compose.infra.yml', 'up', '-d', '--wait'], { cwd: root });
      log.ok('Infrastructure is healthy');
    }
  }

  const { serve: projects, skipped } = planServe(root);
  for (const s of skipped) log.warn(`Not starting ${s.name}: ${s.reason}`);
  for (const name of missingClients(root)) log.warn(`${name} is recorded in nestlaravel.json but apps/${name} is missing.`);
  try {
    if (readManifest(root).portals && !existsSync(join(root, 'apps', 'customer-portal'))) {
      log.warn('nestlaravel.json lists portals but apps/customer-portal is missing — run: nestlaravel add portals');
    }
  } catch { /* manifest problems are reported elsewhere */ }
  if (projects.length === 0) throw new CliError('No project can be served: none defines a "serve" target (or all were skipped, see above).');
  log.step(`Serving: ${projects.join(', ')}`);
  log.info(c.dim('Ctrl+C stops the apps (infrastructure keeps running; stop it with: docker compose -f docker-compose.infra.yml down)'));
  const exclude = skipped.map((x) => x.name);
  await nx(['run-many', '-t', 'serve', `--parallel=${projects.length}`, '--outputStyle=stream', ...(exclude.length ? [`--exclude=${exclude.join(',')}`] : [])], { cwd: root });
}
