import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { copyTree, editFile, writeIfAbsent } from '../fsx.js';
import { getEnv, secret, setEnv } from '../secrets.js';
import { toEnvName } from '../workspace.js';

/**
 * Upgrade migrations, applied in version order by `nestlaravel update`.
 *
 * Contract for every step:
 *  - `needed(ctx)`  pure inspection; true when the workspace still needs this step
 *  - `apply(ctx)`   idempotent; use ctx.edit()/ctx.write() so originals are backed up first
 *  - never delete user code; never overwrite a file the user modified (use managed sync for that)
 */

/** Gateway config entries: [{ key, env }] parsed from apps/api/config/gateway.php. */
function gatewayServices(ctx) {
  const file = join(ctx.root, 'apps', 'api', 'config', 'gateway.php');
  if (!existsSync(file)) return [];
  const text = readFileSync(file, 'utf8');
  const services = [];
  const re = /'([a-z0-9-]+)' => \[\s*\n\s*'enabled' => \(bool\) env\('GATEWAY_([A-Z0-9_]+)_ENABLED'/g;
  for (let m; (m = re.exec(text)); ) services.push({ key: m[1], env: m[2], dir: `${m[1]}-service` });
  return services;
}

/** The `'key' => [ ... ],` block of one service in gateway.php, or null. */
function gatewayBlock(text, key) {
  const m = text.match(new RegExp(`'${key}' => \\[[\\s\\S]*?\\n        \\],`));
  return m ? m[0] : null;
}

const serviceDirs = (ctx) => gatewayServices(ctx).filter((s) => existsSync(join(ctx.root, 'apps', s.dir, 'artisan')));

export const migrations = [
  {
    version: '1.0.0',
    title: 'Adopt NestLaravel 1.0: signed gateway → service trust, Kafka kit, secure defaults',
    steps: [
      {
        title: 'Add packages/laravel-kafka (Kafka kit for microservices)',
        needed: (ctx) => !existsSync(join(ctx.root, 'packages', 'laravel-kafka', 'composer.json')),
        apply: (ctx) => copyTree(join(ctx.templates, 'workspace', 'packages', 'laravel-kafka'), join(ctx.root, 'packages', 'laravel-kafka')),
      },
      {
        title: 'Keep a service template for `generate service` (.nestlaravel/service-template)',
        needed: (ctx) =>
          !existsSync(join(ctx.root, '.nestlaravel', 'service-template')) && !existsSync(join(ctx.root, 'apps', 'orders-service')),
        apply: (ctx) => copyTree(join(ctx.templates, 'service-template'), join(ctx.root, '.nestlaravel', 'service-template')),
      },
      {
        title: 'Gateway config: per-service HMAC secret, no bearer-token forwarding (apps/api/config/gateway.php)',
        needed: (ctx) => {
          const f = join(ctx.root, 'apps', 'api', 'config', 'gateway.php');
          if (!existsSync(f)) return false;
          const text = readFileSync(f, 'utf8');
          return gatewayServices(ctx).some((s) => {
            const block = gatewayBlock(text, s.key);
            return block !== null && (!block.includes("'secret'") || /'forward_auth' => true/.test(block));
          });
        },
        apply: (ctx) => {
          const f = join(ctx.root, 'apps', 'api', 'config', 'gateway.php');
          ctx.edit(f, (text) => {
            let out = text;
            for (const s of gatewayServices(ctx)) {
              const block = gatewayBlock(out, s.key);
              if (block === null) continue;
              let next = block.replace(/'forward_auth' => true,/, "'forward_auth' => false,");
              if (!next.includes("'secret'")) {
                next = next.replace(
                  /(\n\s*)('forward_auth' => (?:true|false),)/,
                  `$1'secret' => env('${s.env}_SERVICE_SECRET', ''),$1'public' => false,$1$2`,
                );
              }
              out = out.replace(block, () => next);
            }
            return out;
          });
        },
      },
      {
        title: 'Services: verify the gateway signature (VerifyGatewaySignature middleware + config/internal.php)',
        needed: (ctx) =>
          serviceDirs(ctx).some((s) => !existsSync(join(ctx.root, 'apps', s.dir, 'app', 'Http', 'Middleware', 'VerifyGatewaySignature.php'))),
        apply: (ctx) => {
          for (const s of serviceDirs(ctx)) {
            const base = join(ctx.root, 'apps', s.dir);
            for (const rel of [join('app', 'Http', 'Middleware', 'VerifyGatewaySignature.php'), join('config', 'internal.php')]) {
              ctx.write(join(base, rel), readFileSync(join(ctx.templates, 'service-template', rel), 'utf8'));
            }
            // Attach the middleware to the module routes (only the standard generated provider is patched).
            const modules = join(base, 'app', 'Modules');
            for (const module of existsSync(modules) ? readdirSync(modules) : []) {
              const provider = join(modules, module, 'Infrastructure', 'Providers', `${module}ServiceProvider.php`);
              if (!existsSync(provider)) continue;
              ctx.edit(provider, (text) => {
                if (text.includes('VerifyGatewaySignature')) return text;
                const patched = text
                  .replace(/->middleware\('api'\)/, "->middleware(['api', VerifyGatewaySignature::class])")
                  .replace(/^(namespace [^\n]+;\r?\n)/m, '$1\nuse App\\Http\\Middleware\\VerifyGatewaySignature;');
                if (patched === text) ctx.warn(`Could not patch ${provider}; add VerifyGatewaySignature to its route middleware manually.`);
                return patched;
              });
            }
          }
        },
      },
      {
        title: 'Secrets: matching gateway/service signing secrets in .env files (generated when missing)',
        needed: (ctx) =>
          gatewayServices(ctx).some((s) => {
            const gw = join(ctx.root, 'apps', 'api', '.env');
            const sv = join(ctx.root, 'apps', s.dir, '.env');
            const a = existsSync(gw) ? getEnv(readFileSync(gw, 'utf8'), `${s.env}_SERVICE_SECRET`) : undefined;
            const b = existsSync(sv) ? getEnv(readFileSync(sv, 'utf8'), 'INTERNAL_SERVICE_SECRET') : undefined;
            return existsSync(sv) && (!a || !b || a !== b);
          }),
        apply: (ctx) => {
          for (const s of serviceDirs(ctx)) {
            const gw = join(ctx.root, 'apps', 'api', '.env');
            const sv = join(ctx.root, 'apps', s.dir, '.env');
            if (!existsSync(gw) || !existsSync(sv)) continue;
            const a = getEnv(readFileSync(gw, 'utf8'), `${s.env}_SERVICE_SECRET`);
            const b = getEnv(readFileSync(sv, 'utf8'), 'INTERNAL_SERVICE_SECRET');
            const value = a || b || secret(32);
            ctx.edit(gw, (t) => setEnv(t, `${s.env}_SERVICE_SECRET`, value));
            ctx.edit(sv, (t) => setEnv(t, 'INTERNAL_SERVICE_SECRET', value));
          }
        },
      },
      {
        title: 'Harden APP_DEBUG defaults in .env.example files (APP_DEBUG=true → false)',
        needed: (ctx) => [join('apps', 'api'), ...serviceDirs(ctx).map((s) => join('apps', s.dir))].some((d) => {
          const f = join(ctx.root, d, '.env.example');
          return existsSync(f) && /^APP_DEBUG=true$/m.test(readFileSync(f, 'utf8'));
        }),
        apply: (ctx) => {
          for (const d of [join('apps', 'api'), ...serviceDirs(ctx).map((s) => join('apps', s.dir))]) {
            const f = join(ctx.root, d, '.env.example');
            if (existsSync(f)) ctx.edit(f, (t) => t.replace(/^APP_DEBUG=true$/m, 'APP_DEBUG=false'));
          }
        },
      },
      {
        title: 'Privilege-escalation guard: restrict self-registration roles (see UPGRADING.md — manual review)',
        // Informational: registration code is user-owned, so it is reported, never rewritten.
        needed: (ctx) => {
          const f = join(ctx.root, 'apps', 'api', 'app', 'Modules', 'Auth', 'Presentation', 'Requests', 'RegisterRequest.php');
          return existsSync(f) && /'role' => \['sometimes'[^\]]*'exists:roles,name'\]/.test(readFileSync(f, 'utf8'));
        },
        apply: (ctx) =>
          ctx.warn(
            'apps/api RegisterRequest lets callers pick ANY role at self-registration (e.g. platform_admin). ' +
              "Restrict it to Rule::in(config('auth.self_registration_roles')) — see UPGRADING.md § 1.0.0.",
          ),
      },
    ],
  },
  {
    version: '1.1.0',
    title: 'Adopt NestLaravel 1.1: inbox idempotency, concurrency-safe outbox, sagas, observability, health probes',
    steps: [
      {
        title: 'Add Kubernetes reference manifests (infrastructure/k8s)',
        needed: (ctx) => !existsSync(join(ctx.root, 'infrastructure', 'k8s')),
        apply: (ctx) => copyTree(join(ctx.templates, 'workspace', 'infrastructure', 'k8s'), join(ctx.root, 'infrastructure', 'k8s')),
      },
      {
        title: 'docker-compose.yml: stop_grace_period so consumers and the outbox publisher can finish before SIGKILL',
        needed: (ctx) => {
          const f = join(ctx.root, 'docker-compose.yml');
          return existsSync(f) && /x-laravel-app:/.test(readFileSync(f, 'utf8')) && !/stop_grace_period/.test(readFileSync(f, 'utf8'));
        },
        apply: (ctx) => {
          const f = join(ctx.root, 'docker-compose.yml');
          let patched = false;
          ctx.edit(f, (text) => {
            const out = text.replace(/(x-laravel-app:[\s\S]*?\n {2}restart: unless-stopped\n)/, (m) => {
              patched = true;
              return `${m}  stop_grace_period: 40s\n`;
            });
            return out;
          });
          if (!patched) ctx.warn('Could not patch docker-compose.yml: add "stop_grace_period: 40s" to the app/worker services.');
        },
      },
      {
        title: 'METRICS_TOKEN: protect /metrics (generated when missing in existing .env files)',
        needed: (ctx) =>
          [join('apps', 'api'), ...serviceDirs(ctx).map((s) => join('apps', s.dir))].some((d) => {
            const f = join(ctx.root, d, '.env');
            return existsSync(f) && !getEnv(readFileSync(f, 'utf8'), 'METRICS_TOKEN');
          }),
        apply: (ctx) => {
          for (const d of [join('apps', 'api'), ...serviceDirs(ctx).map((s) => join('apps', s.dir))]) {
            const f = join(ctx.root, d, '.env');
            if (existsSync(f) && !getEnv(readFileSync(f, 'utf8'), 'METRICS_TOKEN')) ctx.edit(f, (t) => setEnv(t, 'METRICS_TOKEN', secret(24)));
          }
        },
      },
      {
        title: 'Review runtime switches that change behaviour (not applied automatically)',
        // Informational. Turning these on before `php artisan migrate` would break consumers, so they are reported, not set.
        needed: (ctx) => serviceDirs(ctx).length > 0,
        apply: (ctx) =>
          ctx.warn(
            '1.1 adds tables (inbox_events, saga_instances) and outbox columns: run "php artisan migrate" in every service FIRST, ' +
              'then set KAFKA_INBOX_ENABLED=true (transactional idempotency), LOG_CHANNEL=nestlaravel (structured logs) and, once every ' +
              'event has a schema, KAFKA_SCHEMA_ENFORCE_PRODUCER/CONSUMER=true. Then run "nestlaravel production:check". See UPGRADING.md § 1.1.0.',
          ),
      },
    ],
  },
];

export { writeIfAbsent, editFile, toEnvName };
