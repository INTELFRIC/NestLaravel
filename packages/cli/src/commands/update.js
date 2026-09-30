import { copyFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { createInterface } from 'node:readline/promises';
import { parseArgs } from '../args.js';
import { compareVersions, checkRequirements } from '../doctor.js';
import { capture, nx, run } from '../exec.js';
import { ensureDir, templatesDir } from '../fsx.js';
import { managedMap, syncManaged } from '../managed.js';
import { migrations } from '../migrations/index.js';
import { c, CliError, log } from '../ui.js';
import { FRAMEWORK_VERSION, MANIFEST } from '../versions.js';
import { findWorkspace, readManifest, writeManifest } from '../workspace.js';

export const UPDATE_HELP = `
Usage: nestlaravel update [options]

Upgrades an existing workspace to the installed NestLaravel version.

  1. detects the workspace version      4. backs up every file it changes
  2. checks compatibility               5. applies migrations + syncs framework files
  3. prints the plan                    6. runs tests and verifies

Options:
  --dry-run        Show the plan; change nothing
  -y, --yes        Do not ask for confirmation
  --adopt          Adopt a workspace created before NestLaravel had a CLI (writes ${MANIFEST})
  --allow-major    Allow upgrading across a major version
  --skip-install   Do not run npm/composer afterwards
  --skip-tests     Do not run the test suite afterwards
`;

/** A workspace created before the CLI existed: Nx + apps/api Laravel gateway, no manifest. */
const looksLikeLegacy = (dir) => existsSync(join(dir, 'nx.json')) && existsSync(join(dir, 'apps', 'api', 'artisan'));

export async function update(argv) {
  const { flags } = parseArgs(argv, {
    boolean: ['dry-run', 'yes', 'adopt', 'allow-major', 'skip-install', 'skip-tests', 'help'],
    alias: { y: 'yes', h: 'help' },
  });
  if (flags.help) return void console.log(UPDATE_HELP);

  let root = findWorkspace();
  let manifest;
  let adopted = false;

  if (!root) {
    if (looksLikeLegacy(process.cwd())) {
      if (!flags.adopt) {
        throw new CliError(
          'This looks like a NestLaravel workspace created before the CLI existed (no nestlaravel.json).\n' +
            '  Preview the upgrade:  npx nestlaravel update --adopt --dry-run\n' +
            '  Apply it:             npx nestlaravel update --adopt',
        );
      }
      root = process.cwd();
      manifest = { nestlaravel: '0.0.0', createdWith: 'legacy', name: root.split(/[\\/]/).pop(), database: 'sqlite', portals: existsSync(join(root, 'apps', 'customer-portal')), services: [], features: [] };
      adopted = true;
    } else {
      throw new CliError(`Not inside a NestLaravel workspace (no ${MANIFEST}).`);
    }
  } else {
    manifest = readManifest(root);
  }

  const current = manifest.nestlaravel;
  const target = FRAMEWORK_VERSION;

  log.info(`\n${c.bold('NestLaravel update')}  workspace ${c.cyan(current)} → CLI ${c.cyan(target)}\n`);

  // 2. compatibility -------------------------------------------------------------------------------------
  if (compareVersions(current, target) > 0) {
    throw new CliError(`This workspace (${current}) is newer than the installed CLI (${target}). Upgrade the CLI: npm install -D nestlaravel@latest`);
  }
  const majorJump = Number(target.split('.')[0]) > Number(current.split('.')[0]) && current !== '0.0.0';
  if (majorJump && !flags['allow-major']) {
    throw new CliError(`Upgrading ${current} → ${target} crosses a major version. Read UPGRADING.md, then re-run with --allow-major.`);
  }
  const failed = checkRequirements().filter((r) => r.required && !r.ok);
  if (failed.length) throw new CliError(`Fix these first: ${failed.map((f) => `${f.name} (${f.message})`).join('; ')}`);

  const templates = templatesDir();
  const services = [...new Set([
    ...(manifest.services ?? []),
    ...(adopted ? discoverServices(root) : []),
  ])];
  const map = managedMap(templates, services);

  const warnings = [];
  const ctx = {
    root,
    templates,
    backupDir: null,
    dryRun: true,
    warn: (m) => warnings.push(m),
    backup(path) {
      if (this.dryRun || !existsSync(path)) return;
      const dest = join(this.backupDir, relative(root, path));
      if (existsSync(dest)) return;
      ensureDir(dirname(dest));
      copyFileSync(path, dest);
    },
    edit(path, fn) {
      const before = readFileSync(path, 'utf8');
      const after = fn(before);
      if (after !== before && !this.dryRun) {
        this.backup(path);
        writeFileSync(path, after);
      }
    },
    write(path, content) {
      if (existsSync(path)) {
        if (readFileSync(path, 'utf8') === content) return;
        this.backup(path);
      }
      if (!this.dryRun) {
        ensureDir(dirname(path));
        writeFileSync(path, content);
      }
    },
  };

  // 3. plan -----------------------------------------------------------------------------------------------
  const pending = migrations.filter((m) => compareVersions(m.version, current) > 0 && compareVersions(m.version, target) <= 0);
  const steps = pending.flatMap((m) => m.steps.filter((s) => s.needed(ctx)).map((s) => ({ version: m.version, ...s })));
  const plan = syncManaged(root, map);

  log.info(c.bold('Plan'));
  if (adopted) log.info(`  • adopt workspace: write ${MANIFEST} and .nestlaravel/managed.json`);
  for (const s of steps) log.info(`  • [${s.version}] ${s.title}`);
  for (const f of plan.add) log.info(`  • add framework file       ${f}`);
  for (const f of plan.replace) log.info(`  • update framework file    ${f}  ${c.dim('(unmodified since last release)')}`);
  for (const f of plan.conflicts) log.info(`  • ${c.yellow('review needed')}            ${f}  ${c.dim('(you changed it → new version written as .nestlaravel-new)')}`);
  const nothing = !adopted && steps.length === 0 && plan.add.length + plan.replace.length + plan.conflicts.length === 0 && current === target;
  if (nothing) {
    log.ok('Already up to date.');
    return;
  }
  if (!adopted && steps.length + plan.add.length + plan.replace.length + plan.conflicts.length === 0) {
    log.info('  • no file changes; only the recorded version will be bumped');
  }
  log.info(c.dim('\n  Backups go to .nestlaravel/backup/<timestamp>/ — user code is never overwritten.\n'));

  if (flags['dry-run']) {
    log.info('Dry run: nothing was changed.');
    return;
  }

  // confirm --------------------------------------------------------------------------------------------------
  if (!flags.yes) {
    if (!process.stdin.isTTY) throw new CliError('Non-interactive shell: pass --yes to apply the plan (or --dry-run to preview).');
    const rl = createInterface({ input: process.stdin, output: process.stdout });
    const answer = (await rl.question('Apply this plan? [y/N] ')).trim().toLowerCase();
    rl.close();
    if (answer !== 'y' && answer !== 'yes') {
      log.info('Aborted. Nothing was changed.');
      return;
    }
  }

  // 4-5. backup + apply -----------------------------------------------------------------------------------------
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  ctx.backupDir = join(root, '.nestlaravel', 'backup', stamp);
  ctx.dryRun = false;
  ensureDir(ctx.backupDir);
  writeFileSync(join(ctx.backupDir, 'BEFORE.json'), `${JSON.stringify({ manifest, from: current, to: target }, null, 2)}\n`);
  if (existsSync(join(root, 'nestlaravel.json'))) ctx.backup(join(root, 'nestlaravel.json'));
  ctx.backup(join(root, 'package.json'));

  try {
    for (const s of steps) {
      log.step(s.title);
      await s.apply(ctx);
    }
    const applied = syncManaged(root, map, { apply: true });
    if (applied.conflicts.length) warnings.push(`${applied.conflicts.length} framework file(s) need a manual merge: look for *.nestlaravel-new.`);
  } catch (error) {
    throw new CliError(`Update failed midway: ${error.message}\n  Your originals are in ${relative(root, ctx.backupDir)}. The recorded version was NOT changed.`);
  }

  // 5. dependencies ------------------------------------------------------------------------------------------------
  const pkgPath = join(root, 'package.json');
  if (existsSync(pkgPath)) {
    const pkg = JSON.parse(readFileSync(pkgPath, 'utf8'));
    const spec = `^${target}`;
    if (!process.env.NESTLARAVEL_CLI_SPEC && pkg.devDependencies?.nestlaravel !== spec) {
      pkg.devDependencies = { ...pkg.devDependencies, nestlaravel: spec };
      writeFileSync(pkgPath, `${JSON.stringify(pkg, null, 2)}\n`);
      if (!flags['skip-install']) await run('npm', ['install', '--no-audit', '--no-fund'], { cwd: root });
    }
  }

  manifest.nestlaravel = target;
  manifest.updatedAt = new Date().toISOString();
  manifest.history = [...(manifest.history ?? []), { from: current, to: target, at: manifest.updatedAt }];
  writeManifest(root, manifest);

  // 8-9. tests + verification -----------------------------------------------------------------------------------------
  if (!flags['skip-tests'] && !flags['skip-install'] && existsSync(join(root, 'node_modules', '.bin'))) {
    log.step('Running the test suite');
    await nx(['run-many', '-t', 'test'], { cwd: root });
  }
  if (!flags['skip-install'] && capture('composer', ['--version'])) {
    for (const dir of ['api', ...services.map((s) => `${s}-service`)]) {
      const app = join(root, 'apps', dir);
      if (existsSync(join(app, 'composer.lock'))) {
        const audit = await run('composer', ['audit', '--no-interaction'], { cwd: app, silent: true, allowFailure: true });
        if (audit.code !== 0) warnings.push(`composer audit reports advisories for apps/${dir} — run "composer update" there and test.`);
      }
    }
  }

  log.blank();
  log.ok(`Workspace is now on NestLaravel ${c.bold(target)}`);
  log.info(c.dim(`Backup: ${relative(root, ctx.backupDir)}`));
  for (const w of warnings) log.warn(w);
  if (warnings.length === 0) log.info(c.dim('No manual steps required.'));
}

function discoverServices(root) {
  const gateway = join(root, 'apps', 'api', 'config', 'gateway.php');
  if (!existsSync(gateway)) return [];
  const text = readFileSync(gateway, 'utf8');
  const names = [];
  for (const m of text.matchAll(/'prefix' => '([a-z0-9-]+)'/g)) {
    if (existsSync(join(root, 'apps', `${m[1]}-service`, 'artisan'))) names.push(m[1]);
  }
  return names;
}
