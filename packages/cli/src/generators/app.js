import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { capture, run } from '../exec.js';
import { copyTree, ensureDir, templatesDir, writeIfAbsent } from '../fsx.js';
import { getEnv, setEnv } from '../secrets.js';
import { c, CliError, log } from '../ui.js';
import { readManifest, validateName, writeManifest } from '../workspace.js';

export const GATEWAY_URL = 'http://localhost:8000/api';
export const APP_KINDS = { web: { stack: 'next', basePort: 3002 }, mobile: { stack: 'flutter', basePort: 8090 } };
const PORTALS = [
  { name: 'customer-portal', port: 3000 },
  { name: 'admin-portal', port: 3001 },
];
const CORS_KEY = 'CORS_ALLOWED_ORIGINS';

/** Both spellings of a dev origin: browsers treat localhost and 127.0.0.1 as different origins. */
const originsFor = (port) => [`http://localhost:${port}`, `http://127.0.0.1:${port}`];

/** Ports already claimed by the workspace's clients (project.json commands + package.json dev scripts). */
export function usedPorts(root) {
  const ports = new Set();
  const apps = join(root, 'apps');
  if (!existsSync(apps)) return ports;
  const scan = (text) => {
    for (const m of text.matchAll(/(?:\s-p|--port|--web-port)[ =](\d{2,5})\b/g)) ports.add(Number(m[1]));
  };
  for (const entry of readdirSync(apps, { withFileTypes: true })) {
    if (!entry.isDirectory()) continue;
    for (const file of ['project.json', 'package.json']) {
      const path = join(apps, entry.name, file);
      if (existsSync(path)) scan(readFileSync(path, 'utf8'));
    }
  }
  return ports;
}

function pickPort(root, kind, requested) {
  const used = usedPorts(root);
  if (requested != null) {
    const port = Number(requested);
    if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new CliError('--port must be an integer between 1024 and 65535.');
    if (used.has(port)) throw new CliError(`Port ${port} is already used by another app in this workspace.`);
    return port;
  }
  let port = APP_KINDS[kind].basePort;
  while (used.has(port)) port++;
  return port;
}

/** Add the app's browser origins to the gateway's CORS list (apps/api/.env and .env.example). */
export function allowOrigins(root, port) {
  const added = [];
  for (const file of ['.env', '.env.example']) {
    const path = join(root, 'apps', 'api', file);
    if (!existsSync(path)) continue;
    const text = readFileSync(path, 'utf8');
    const current = (getEnv(text, CORS_KEY) ?? '').split(',').map((s) => s.trim()).filter(Boolean);
    const next = [...new Set([...current, ...originsFor(port)])];
    if (next.length === current.length) continue;
    writeFileSync(path, setEnv(text, CORS_KEY, next.join(',')));
    added.push(`apps/api/${file}`);
  }
  return added;
}

/** Register a client in nestlaravel.json (`clients`) and keep `portals` truthful. */
function recordClient(root, client) {
  const manifest = readManifest(root);
  manifest.clients = [...(manifest.clients ?? []).filter((x) => x.name !== client.name), client];
  if (client.kind === 'portal') manifest.portals = true;
  writeManifest(root, manifest);
}

function addWorkspaces(root, entries) {
  const path = join(root, 'package.json');
  const pkg = JSON.parse(readFileSync(path, 'utf8'));
  pkg.workspaces = [...new Set([...(pkg.workspaces ?? []), ...entries])];
  writeFileSync(path, `${JSON.stringify(pkg, null, 2)}\n`);
}

async function npmInstall(root, flags) {
  if (flags['skip-install']) return log.warn('Skipped npm install (--skip-install). Run it in the workspace root before "nestlaravel dev".');
  log.step('Installing Node dependencies');
  await run('npm', ['install', '--no-audit', '--no-fund'], { cwd: root });
}

/** nestlaravel add portals — the Next.js customer (:3000) and admin (:3001) portals. */
export async function addPortals(root, flags = {}) {
  const missing = PORTALS.filter((p) => !existsSync(join(root, 'apps', p.name)));
  if (missing.length === 0 && existsSync(join(root, 'packages', 'api-client'))) {
    log.ok('Portals are already installed (apps/customer-portal, apps/admin-portal).');
  } else {
    log.step('Adding the customer and admin portals');
    // copyTree never overwrites, so a customised portal or api-client is left alone.
    copyTree(join(templatesDir(), 'optional', 'portals'), root);
  }

  for (const p of PORTALS) {
    // The template build drops .env.* files, so the portal's gateway URL is written here.
    writeIfAbsent(join(root, 'apps', p.name, '.env.local'), `NEXT_PUBLIC_API_URL=${GATEWAY_URL}
`);
    allowOrigins(root, p.port);
    recordClient(root, { name: p.name, kind: 'portal', stack: 'next', port: p.port });
  }
  addWorkspaces(root, ['apps/customer-portal', 'apps/admin-portal', 'packages/api-client']);
  await npmInstall(root, flags);

  log.ok('Portals added. They start with the API:');
  log.cmd('npx nestlaravel dev');
  log.info(c.dim('  customer-portal → http://localhost:3000   admin-portal → http://localhost:3001   (gateway CORS allows both)'));
}

// ---------------------------------------------------------------------------------------------------
// generate app web|mobile
// ---------------------------------------------------------------------------------------------------

const json = (value) => `${JSON.stringify(value, null, 2)}\n`;

export function projectJson(name, kind, port) {
  const cwd = `apps/${name}`;
  const cmd = (command) => ({ executor: 'nx:run-commands', options: { command, cwd } });
  const targets =
    kind === 'web'
      ? { serve: cmd('npm run dev'), build: cmd('npm run build'), lint: cmd('npm run lint') }
      : {
          // `web-server` serves the Flutter web build over HTTP: no emulator or Chrome window to pick,
          // so `nestlaravel dev` can start it unattended. Use `nx run <name>:device` for a phone/emulator.
          serve: cmd(`flutter run -d web-server --web-hostname 127.0.0.1 --web-port ${port} --dart-define=API_URL=${GATEWAY_URL}`),
          device: cmd('flutter run --dart-define=API_URL=http://10.0.2.2:8000/api'),
          test: cmd('flutter test'),
          lint: cmd('flutter analyze'),
          build: cmd('flutter build web --release'),
          'build:apk': cmd('flutter build apk --release'),
        };
  return json({
    name,
    $schema: '../../node_modules/nx/schemas/project-schema.json',
    projectType: 'application',
    sourceRoot: kind === 'web' ? `${cwd}/src` : `${cwd}/lib`,
    tags: ['type:client', `stack:${APP_KINDS[kind].stack}`, `kind:${kind}`],
    targets,
  });
}

const webFiles = (name, port) => ({
  'package.json': json({
    name,
    version: '0.1.0',
    private: true,
    scripts: { dev: `next dev -p ${port}`, build: 'next build', start: `next start -p ${port}`, lint: 'eslint' },
    dependencies: { next: '16.4.2', react: '19.2.8', 'react-dom': '19.2.8' },
    devDependencies: { '@types/node': '^20', '@types/react': '^19', '@types/react-dom': '^19', eslint: '^9', 'eslint-config-next': '16.4.2', typescript: '^5' },
  }),
  'tsconfig.json': json({
    compilerOptions: {
      target: 'ES2017', lib: ['dom', 'dom.iterable', 'esnext'], allowJs: true, skipLibCheck: true, strict: true, noEmit: true,
      esModuleInterop: true, module: 'esnext', moduleResolution: 'bundler', resolveJsonModule: true, isolatedModules: true,
      jsx: 'react-jsx', incremental: true, plugins: [{ name: 'next' }], paths: { '@/*': ['./src/*'] },
    },
    include: ['next-env.d.ts', '**/*.ts', '**/*.tsx', '.next/types/**/*.ts', '.next/dev/types/**/*.ts', '**/*.mts'],
    exclude: ['node_modules'],
  }),
  'next.config.ts': "import type { NextConfig } from 'next';\n\nconst nextConfig: NextConfig = {};\n\nexport default nextConfig;\n",
  'eslint.config.mjs':
    'import { defineConfig, globalIgnores } from "eslint/config";\nimport nextVitals from "eslint-config-next/core-web-vitals";\nimport nextTs from "eslint-config-next/typescript";\n\n' +
    'export default defineConfig([...nextVitals, ...nextTs, globalIgnores([".next/**", "out/**", "build/**", "next-env.d.ts"])]);\n',
  '.gitignore': '/node_modules\n/.next/\n/out/\n/build\n.DS_Store\n*.pem\n.env*\n!.env.local.example\n*.tsbuildinfo\nnext-env.d.ts\n',
  '.env.local.example': `NEXT_PUBLIC_API_URL=${GATEWAY_URL}\n`,
  '.env.local': `NEXT_PUBLIC_API_URL=${GATEWAY_URL}\n`,
  'src/lib/api.ts': `const baseUrl = (process.env.NEXT_PUBLIC_API_URL ?? '${GATEWAY_URL}').replace(/\\/$/, '');

/** Call the gateway (never a microservice directly). Pass the bearer token from POST /auth/login. */
export async function api<T>(path: string, init: RequestInit & { token?: string | null } = {}): Promise<T> {
  const { token, ...rest } = init;
  const headers = new Headers(rest.headers);
  headers.set('Accept', 'application/json');
  if (rest.body) headers.set('Content-Type', 'application/json');
  if (token) headers.set('Authorization', \`Bearer \${token}\`);
  const res = await fetch(\`\${baseUrl}\${path}\`, { ...rest, headers });
  const body = await res.json().catch(() => null);
  if (!res.ok) throw new Error(body?.message ?? \`Request failed (\${res.status})\`);
  return body as T;
}
`,
  'src/app/layout.tsx': `import type { ReactNode } from 'react';

export const metadata = { title: '${name}' };

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
`,
  'src/app/page.tsx': `export default function Home() {
  return (
    <main style={{ padding: '2rem', fontFamily: 'system-ui, sans-serif' }}>
      <h1>${name}</h1>
      <p>
        Connected to the gateway at <code>{process.env.NEXT_PUBLIC_API_URL}</code>. Sign in with <code>POST /auth/login</code> via{' '}
        <code>src/lib/api.ts</code>.
      </p>
    </main>
  );
}
`,
});

const mobileFiles = (name) => {
  const pkg = name.replace(/-/g, '_');
  return {
    'pubspec.yaml': `name: ${pkg}
description: NestLaravel mobile client for the gateway.
publish_to: none
version: 0.1.0+1

environment:
  sdk: ">=3.3.0 <4.0.0"

dependencies:
  flutter:
    sdk: flutter
  http: ^1.2.0
  flutter_secure_storage: ^9.2.0

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^4.0.0

flutter:
  uses-material-design: true
`,
    'analysis_options.yaml': 'include: package:flutter_lints/flutter.yaml\n',
    '.gitignore': '.dart_tool/\n.packages\nbuild/\n.flutter-plugins\n.flutter-plugins-dependencies\n.idea/\n*.iml\n.env*\n',
    'lib/api_client.dart': `import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

/// Talks to the gateway only. Override at run/build time: --dart-define=API_URL=...
/// (Android emulator: http://10.0.2.2:8000/api, physical phone: your LAN IP.)
class ApiClient {
  ApiClient({String? baseUrl})
      : baseUrl = baseUrl ?? const String.fromEnvironment('API_URL', defaultValue: '${GATEWAY_URL}');
  final String baseUrl;
  final _store = const FlutterSecureStorage();

  Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body]) async {
    final token = await _store.read(key: 'access_token');
    final req = http.Request(method, Uri.parse('$baseUrl$path'))
      ..headers.addAll({
        'Accept': 'application/json',
        if (body != null) 'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      });
    if (body != null) req.body = jsonEncode(body);
    final res = await http.Response.fromStream(await req.send().timeout(const Duration(seconds: 15)));
    final data = jsonDecode(res.body) as Map<String, dynamic>;
    if (res.statusCode == 401) await _store.delete(key: 'access_token');
    if (res.statusCode >= 400) throw Exception(data['message'] ?? 'Request failed (\${res.statusCode})');
    return data;
  }

  Future<void> login(String email, String password) async {
    final data = (await _send('POST', '/auth/login', {'email': email, 'password': password}))['data'];
    await _store.write(key: 'access_token', value: data['token'] as String);
  }

  Future<Map<String, dynamic>> me() async => (await _send('GET', '/auth/me'))['data'] as Map<String, dynamic>;

  /// Any service behind the gateway, e.g. get('/v1/orders/42').
  Future<Map<String, dynamic>> get(String path) => _send('GET', path);
}
`,
    'lib/main.dart': `import 'package:flutter/material.dart';
import 'api_client.dart';

void main() => runApp(const App());

class App extends StatelessWidget {
  const App({super.key});

  @override
  Widget build(BuildContext context) => MaterialApp(
        title: '${name}',
        home: Scaffold(
          appBar: AppBar(title: const Text('${name}')),
          body: Center(child: Text('Gateway: \${ApiClient().baseUrl}')),
        ),
      );
}
`,
    'test/widget_test.dart': `import 'package:flutter_test/flutter_test.dart';
import 'package:${pkg}/main.dart';

void main() {
  testWidgets('shows the gateway URL', (tester) async {
    await tester.pumpWidget(const App());
    expect(find.textContaining('Gateway:'), findsOneWidget);
  });
}
`,
  };
};

/** nestlaravel generate app <web|mobile> [name] */
export async function generateApp(root, kind, nameInput, flags = {}) {
  if (!APP_KINDS[kind]) throw new CliError(`Unknown app type "${kind}". Available: web, mobile`);
  const name = validateName(nameInput ?? kind, { kind: 'app name', allowReserved: true });
  const dir = join(root, 'apps', name);
  if (existsSync(dir) && !flags.force) throw new CliError(`apps/${name} already exists. Pass --force to write the missing files.`);

  const port = pickPort(root, kind, flags.port);
  log.step(`Creating apps/${name} (${kind === 'web' ? 'Next.js' : 'Flutter'}, port ${port})`);
  ensureDir(dir);

  // Flutter's own tool owns the platform folders (android/, ios/, web/); our files go on top of it.
  const hasFlutter = kind === 'mobile' && !flags['skip-install'] && capture('flutter', ['--version']) != null;
  if (kind === 'mobile' && !flags['skip-install']) {
    if (hasFlutter) {
      await run('flutter', ['create', '.', '--platforms', 'android,ios,web', '--project-name', name.replace(/-/g, '_')], { cwd: dir });
    } else {
      log.warn(`Flutter SDK not found: wrote the Dart sources only. Install Flutter, then run "flutter create . --platforms android,ios,web" in apps/${name}.`);
    }
  }

  const files = { ...(kind === 'web' ? webFiles(name, port) : mobileFiles(name)), 'project.json': projectJson(name, kind, port) };
  for (const [rel, content] of Object.entries(files)) {
    writeIfAbsent(join(dir, rel), content, { overwrite: kind === 'mobile' && /^(lib|test)\/|^pubspec\.yaml$/.test(rel) });
  }

  // Browser-served clients need their origin on the gateway's CORS list (the Flutter dev server is web).
  const cors = allowOrigins(root, port);
  recordClient(root, { name, kind, stack: APP_KINDS[kind].stack, port });

  if (kind === 'web') {
    addWorkspaces(root, [`apps/${name}`]);
    await npmInstall(root, flags);
  } else if (hasFlutter) {
    await run('flutter', ['pub', 'get'], { cwd: dir });
  }

  log.ok(`apps/${name} created. Gateway: ${GATEWAY_URL}${cors.length ? `  ·  CORS updated in ${cors.join(', ')}` : ''}`);
  log.info(`  Nx target ${c.bold('serve')} → http://localhost:${port}   (started by ${c.bold('npx nestlaravel dev')})`);
  if (kind === 'mobile') log.info(c.dim(`  Real device or emulator: npx nx run ${name}:device   (Android emulator reaches the gateway at 10.0.2.2:8000)`));
  if (cors.length) log.info(c.dim('  Restart the gateway to pick up the new CORS origin.'));
}
