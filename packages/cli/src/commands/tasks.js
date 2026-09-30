import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs } from '../args.js';
import { capture, nx, run } from '../exec.js';
import { c, CliError, log } from '../ui.js';
import { requireWorkspace } from '../workspace.js';

/** Projects that define `target`, in declaration order (reads project.json, no Nx needed). */
function projectsWith(root, target) {
  const found = [];
  const apps = join(root, 'apps');
  if (!existsSync(apps)) return found;
  for (const entry of readdirSync(apps, { withFileTypes: true })) {
    const file = join(apps, entry.name, 'project.json');
    if (!entry.isDirectory() || !existsSync(file)) continue;
    try {
      const json = JSON.parse(readFileSync(file, 'utf8'));
      if (json.targets?.[target]) found.push(json.name ?? entry.name);
    } catch {
      /* ignore malformed project.json — Nx will report it */
    }
  }
  return found;
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
        throw new CliError('Missing .env in the workspace root (holds DB_PASSWORD / REDIS_PASSWORD). Re-create it from .env.example.');
      }
      log.step('Starting infrastructure (Postgres, Redis, Kafka KRaft)');
      await run('docker', ['compose', '-f', 'docker-compose.infra.yml', 'up', '-d', '--wait'], { cwd: root });
      log.ok('Infrastructure is healthy');
    }
  }

  const projects = projectsWith(root, 'serve');
  if (projects.length === 0) throw new CliError('No project defines a "serve" target.');
  log.step(`Serving: ${projects.join(', ')}`);
  log.info(c.dim('Ctrl+C stops the apps (infrastructure keeps running; stop it with: docker compose -f docker-compose.infra.yml down)'));
  await nx(['run-many', '-t', 'serve', `--parallel=${projects.length}`, '--outputStyle=stream'], { cwd: root });
}
