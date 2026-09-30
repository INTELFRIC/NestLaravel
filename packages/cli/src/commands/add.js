import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs } from '../args.js';
import { run } from '../exec.js';
import { copyTree, templatesDir } from '../fsx.js';
import { c, CliError, log } from '../ui.js';
import { readManifest, requireWorkspace, validateName, writeManifest } from '../workspace.js';

export const ADD_HELP = `
Usage: nestlaravel add <feature> [options]

Features:
  tenancy    Optional multi-tenancy package (nestlaravel/tenancy): single-database tenant isolation for
             Eloquent, queued jobs, cache keys, storage paths and Kafka events.

Options:
  --service <name>   Service(s) to install into (repeatable via comma list; "api" = gateway). Default: all services.
  --skip-install     Copy the package but do not run composer
`;

export async function add(argv) {
  const { positionals, flags } = parseArgs(argv, { boolean: ['skip-install', 'help'], alias: { h: 'help' } });
  const feature = positionals[0];
  if (flags.help || !feature) return void console.log(ADD_HELP);
  if (feature !== 'tenancy') throw new CliError(`Unknown feature "${feature}". Available: tenancy`);

  const root = requireWorkspace();
  const manifest = readManifest(root);

  const requested = flags.service
    ? String(flags.service).split(',').map((s) => (s === 'api' ? 'api' : validateName(s, { kind: 'service name' })))
    : manifest.services ?? [];
  if (requested.length === 0) throw new CliError('No services to install into. Create one first: nestlaravel generate service <name>');

  const pkgDir = join(root, 'packages', 'laravel-tenancy');
  if (!existsSync(pkgDir)) {
    log.step('Adding packages/laravel-tenancy');
    copyTree(join(templatesDir(), 'optional', 'laravel-tenancy'), pkgDir);
  }

  for (const name of requested) {
    const dir = name === 'api' ? 'api' : `${name}-service`;
    const app = join(root, 'apps', dir);
    if (!existsSync(join(app, 'composer.json'))) throw new CliError(`apps/${dir} not found.`);
    log.step(`Installing nestlaravel/tenancy into apps/${dir}`);
    // The Kafka kit is a path repository in services; tenancy declares it as a dev suggestion only.
    await run('composer', ['config', 'repositories.nestlaravel-tenancy', 'path', '../../packages/laravel-tenancy', '--no-interaction'], { cwd: app });
    if (!flags['skip-install']) {
      await run('composer', ['require', 'nestlaravel/tenancy:@dev', '--no-interaction', '--no-progress'], { cwd: app });
    }
  }

  manifest.features = [...new Set([...(manifest.features ?? []), 'tenancy'])];
  writeManifest(root, manifest);

  log.ok('Tenancy installed. Finish the integration (once per service):');
  log.info(`
  1. Migrations: add ${c.bold('$table->tenantId();')} to every tenant-owned table.
  2. Models:     ${c.bold('use NestLaravel\\Tenancy\\Concerns\\BelongsToTenant;')}  (scopes queries, stamps + protects tenant_id)
  3. Routes:     add ${c.bold('NestLaravel\\Tenancy\\Http\\Middleware\\IdentifyTenant::class')} AFTER VerifyGatewaySignature.
  4. Gateway:    the user model needs a ${c.bold('tenant_id')} attribute; the gateway signs it into every service call.
  5. Kafka:      wrap consumers with ${c.bold('NestLaravel\\Tenancy\\Kafka\\TenantAwareHandler')}; events get tenant_id automatically.
  6. Cache/files: use TenantContext::cacheKey() / TenantContext::path().

  Docs: docs/MULTI-TENANCY.md  ·  Tests: packages/laravel-tenancy/tests (cross-tenant access attempts)
`);
}
