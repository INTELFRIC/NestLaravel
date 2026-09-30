# Adding web and mobile apps

NestLaravel does not force a frontend stack. Any web or mobile client — **Next.js, React, plain HTML/JS, TypeScript,
Flutter, Vue, Angular, React Native, Swift, Kotlin** — can sit in the workspace or live in its own repository. There is
no generator to learn: you create the app with that framework's own tool, then connect it with the five steps below.

## Pick your client type

| Type | Runs on | Good choices | Section |
|------|---------|--------------|---------|
| **Web-based** | any browser | Next.js, React (Vite), Vue/Angular/Svelte, plain HTML/JS, TypeScript | [Web](#web-based-clients) |
| **Desktop-based** | Windows · macOS · Linux | Electron, Tauri (web UI in a native shell), Flutter desktop | [Desktop](#desktop-based-clients) |
| **Mobile-based** | Android · iOS | Flutter, React Native, Swift, Kotlin | [Mobile](#mobile-based-clients) |

All three use the same gateway API, the same login flow and the same five steps below.

## The one rule

```text
Web / mobile app ──HTTPS──▶ gateway  (apps/api)  ──signed HTTP──▶ services
                    never ─────────────────────────────────────▶ services
```

Clients talk to the **gateway only**. Services are internal (no public ports, they reject unsigned calls) and
credentials/secrets never belong in a client bundle.

| What | Value (local) |
|------|---------------|
| Gateway base URL | `http://127.0.0.1:8000/api` |
| Register / login | `POST /auth/register`, `POST /auth/login` → `{ "success": true, "data": { "token": "…", "user": {…} } }` |
| Current user / logout | `GET /auth/me`, `POST /auth/logout` with `Authorization: Bearer <token>` |
| Your services | `/api/v1/<service>/…` (e.g. `/api/v1/orders/health`) — needs the bearer token |
| Response envelope | `{ "success": bool, "data": …, "message": string\|null, "errors"?: … }` |
| Limits | login/register are throttled (HTTP 429); tokens expire after 24 h (401 → sign in again) |

Try it before writing any UI:

```bash
curl -s -X POST http://127.0.0.1:8000/api/auth/login -H "Accept: application/json" -H "Content-Type: application/json" \
     -d '{"email":"you@example.com","password":"…"}'
```

## The five steps (any stack)

1. **Create the app** with the framework's own tool, inside `apps/<name>` (or anywhere else).
2. **Register it with Nx** (optional but recommended): add `apps/<name>/project.json` so `nx serve|build|test <name>` and
   `npx nestlaravel dev|test|build` include it.
3. **Point it at the gateway**: set the base URL via that stack's env mechanism (never hard-code production URLs).
4. **Allow its origin** in the gateway's CORS list (browser apps only): in `apps/api/.env`
   ```env
   CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
   ```
   Restart the gateway. Use exact origins; **never `*`**. Native mobile apps do not use CORS.
5. **Sign in and call the API**: log in, keep the token, send `Authorization: Bearer <token>`.

Nx `project.json` template (adjust the commands and port):

```json
{
  "name": "web",
  "$schema": "../../node_modules/nx/schemas/project-schema.json",
  "projectType": "application",
  "sourceRoot": "apps/web/src",
  "tags": ["type:client", "stack:next"],
  "targets": {
    "serve": { "executor": "nx:run-commands", "options": { "command": "npm run dev", "cwd": "apps/web" } },
    "build": { "executor": "nx:run-commands", "options": { "command": "npm run build", "cwd": "apps/web" } },
    "lint":  { "executor": "nx:run-commands", "options": { "command": "npm run lint", "cwd": "apps/web" } }
  }
}
```

A target named `serve` is started by `npx nestlaravel dev`; `test`, `lint` and `build` are run by the matching commands.

> If the app has its own `package.json`, add its folder to the root `package.json` → `"workspaces"` (for example
> `"apps/web"`) and run `npm install` at the root. Skip this for Flutter and static HTML.

---

## Web-based clients

Browser apps: [Next.js](#nextjs-typescript), [React + Vite](#react-vite--typescript), [plain HTML/JS](#plain-html--javascript-no-build-step)
and the [shared TypeScript client](#typescript-client-shared). They need the origin in `CORS_ALLOWED_ORIGINS`.

## Next.js (TypeScript)

```bash
cd apps
npx create-next-app@latest web --ts --app --src-dir --eslint --use-npm --import-alias "@/*"
```

1. Set the dev port so it does not clash with the gateway: in `apps/web/package.json` use `"dev": "next dev -p 3000"`.
2. Add the Nx `project.json` above (`name: web`, `stack:next`).
3. Env — `apps/web/.env.local` (public values only):
   ```env
   NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api
   ```
4. CORS: add `http://localhost:3000,http://127.0.0.1:3000` (step 4 above).
5. Client — `apps/web/src/lib/api.ts` (see the [TypeScript client](#typescript-client-shared) below for `createApiClient`):
   ```ts
   import { createApiClient } from '@/lib/gateway-client';

   export const api = createApiClient({
     baseUrl: process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api',
     getToken: () => (typeof window === 'undefined' ? null : sessionStorage.getItem('access_token')),
   });
   ```
   Call it from a client component (`'use client'`) or a Server Action / Route Handler.
6. Run: `npx nestlaravel dev` (or `npx nx serve web`). Build: `npx nx build web`.

**Server-side calls (recommended for sensitive apps):** call the gateway from Route Handlers/Server Actions and keep the
token in an `httpOnly`, `Secure`, `SameSite=Lax` cookie set by your Next.js server (a small BFF). Then JavaScript in the
browser never sees the token, which removes the main XSS token-theft risk. Use a server-only env var
(`GATEWAY_URL`, without `NEXT_PUBLIC_`).

## React (Vite + TypeScript)

```bash
cd apps
npm create vite@latest web -- --template react-ts
```

1. In `apps/web/vite.config.ts` fix the port: `server: { host: '127.0.0.1', port: 5173, strictPort: true }`.
2. Add the Nx `project.json` (`stack:react`; `serve` → `npm run dev`, `build` → `npm run build`, `lint` → `npm run lint`).
3. Env — `apps/web/.env`:
   ```env
   VITE_API_URL=http://127.0.0.1:8000/api
   ```
4. CORS: `http://localhost:5173,http://127.0.0.1:5173`.
5. Client: copy the [TypeScript client](#typescript-client-shared) to `src/lib/gateway-client.ts` and use
   `import.meta.env.VITE_API_URL` as `baseUrl`.
6. Add the folder to root `workspaces`, `npm install`, then `npx nestlaravel dev`.

Only variables prefixed `VITE_` are exposed to the browser — keep secrets out of them.

## Plain HTML + JavaScript (no build step)

```text
apps/site/
  index.html   app.js   config.js   project.json
```

`config.js`:

```js
export const API_URL = 'http://127.0.0.1:8000/api'; // public value
```

`app.js` (ES module, uses `fetch`):

```js
import { API_URL } from './config.js';

async function api(path, { method = 'GET', body } = {}) {
  const token = sessionStorage.getItem('access_token');
  const res = await fetch(`${API_URL}${path}`, {
    method,
    headers: { Accept: 'application/json', ...(body && { 'Content-Type': 'application/json' }), ...(token && { Authorization: `Bearer ${token}` }) },
    body: body && JSON.stringify(body),
  });
  const json = await res.json().catch(() => null);
  if (!res.ok) throw new Error(json?.message ?? `HTTP ${res.status}`);
  return json;
}

const { data } = await api('/auth/login', { method: 'POST', body: { email, password } });
sessionStorage.setItem('access_token', data.token);
const me = (await api('/auth/me')).data;
document.querySelector('#who').textContent = me.name; // textContent, never innerHTML with server data
```

Serve it with any static server (`npx serve apps/site -l 4000` or `python -m http.server 4000 -d apps/site`), add
`http://localhost:4000,http://127.0.0.1:4000` to CORS, and optionally a `project.json` whose `serve` target runs that command.
Deploy the folder to any static host.

## TypeScript client (shared)

Any TypeScript app (Next, React, Vue, Angular, Node scripts) can use one small typed client. Put it in
`packages/api-client/src/index.ts` (and make the package a workspace member), or copy it into a single app:

```ts
export type ApiEnvelope<T> = { success: boolean; data: T; message: string | null; errors?: Record<string, string[]> };
export type AuthUser = { id: number | string; name: string; email: string; roles?: string[] };

export class ApiError extends Error {
  constructor(message: string, public status: number, public body?: unknown) { super(message); }
}

export function createApiClient(opts: { baseUrl: string; getToken?: () => string | null; onUnauthorized?: () => void }) {
  const baseUrl = opts.baseUrl.replace(/\/$/, '');

  async function request<T>(path: string, init: RequestInit = {}): Promise<ApiEnvelope<T>> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (init.body) headers.set('Content-Type', 'application/json');
    const token = opts.getToken?.();
    if (token) headers.set('Authorization', `Bearer ${token}`);

    const res = await fetch(`${baseUrl}${path}`, { ...init, headers });
    const body = (await res.json().catch(() => null)) as ApiEnvelope<T> | null;
    if (res.status === 401) opts.onUnauthorized?.();
    if (!res.ok || !body) throw new ApiError(body?.message ?? `Request failed (${res.status})`, res.status, body);
    return body;
  }

  return {
    login: (email: string, password: string) =>
      request<{ token: string; user: AuthUser }>('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) }),
    me: () => request<AuthUser>('/auth/me'),
    logout: () => request<null>('/auth/logout', { method: 'POST' }),
    /** Any service behind the gateway, e.g. get('/v1/orders/42'). */
    get: <T>(path: string) => request<T>(path),
    post: <T>(path: string, data: unknown) => request<T>(path, { method: 'POST', body: JSON.stringify(data) }),
  };
}
```

To share it across apps: create `packages/api-client/package.json` (`"name": "@workspace/api-client", "main": "./src/index.ts"`),
add `"packages/api-client"` to root `workspaces`, add `"@workspace/api-client": "*"` to each app's dependencies, and
(Next.js) `transpilePackages: ['@workspace/api-client']` in `next.config.ts`. The repository's own
`packages/api-client` is exactly this pattern.

## Desktop-based clients

Desktop apps are usually a web UI (React/Vite, Next.js static export, plain HTML) inside a native shell, or a Flutter
desktop build. Build the UI exactly as in the web sections above, then wrap it:

### Tauri (small, Rust shell) — web UI + native window

```bash
cd apps
npm create tauri-app@latest desktop      # choose: TypeScript, npm, React (Vite)
cd desktop && npm install
npm run tauri dev                        # build: npm run tauri build  → installers for the current OS
```

* Requires the Rust toolchain and your OS's WebView build tools (see the Tauri prerequisites page).
* **CORS:** the UI origin is `tauri://localhost` (or `http://tauri.localhost` on Windows). Either add that origin to
  `CORS_ALLOWED_ORIGINS`, or — better — make requests from Rust with `tauri-plugin-http` (no browser CORS at all).
* **Token storage:** OS keychain (for example the `keyring` crate / `tauri-plugin-stronghold`), not `localStorage`.
* Set the gateway URL through a Vite env var (`VITE_API_URL`) or `tauri.conf.json` — never embed secrets.

### Electron (Node shell) — web UI + native window

```bash
cd apps
npm create vite@latest desktop -- --template react-ts
cd desktop && npm install
npm install -D electron electron-builder concurrently wait-on cross-env
```

Minimal `electron/main.cjs`:

```js
const { app, BrowserWindow, ipcMain, safeStorage } = require('electron');
const path = require('node:path');

app.whenReady().then(() => {
  const win = new BrowserWindow({
    width: 1100, height: 760,
    webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: true, preload: path.join(__dirname, 'preload.cjs') },
  });
  const dev = process.env.ELECTRON_DEV_URL;
  dev ? win.loadURL(dev) : win.loadFile(path.join(__dirname, '../dist/index.html'));
});
```

Wire it in `package.json`: `"main": "electron/main.cjs"`, `"dev:electron": "concurrently \"vite --port 5174 --strictPort\" \"wait-on tcp:5174 && cross-env ELECTRON_DEV_URL=http://localhost:5174 electron .\""`.

* **Security defaults are not optional:** `contextIsolation: true`, `nodeIntegration: false`, `sandbox: true`, load only your own
  code, set a CSP, expose a minimal API through `preload` (`contextBridge`).
* **CORS:** a packaged app loads from `file://` (origin `null`). Do the gateway calls in the **main process**
  (`fetch` in Node has no CORS) and expose them through IPC, or serve the UI from a custom protocol and allow that origin.
* **Token storage:** encrypt with `safeStorage.encryptString()` (OS keychain-backed) and keep the ciphertext in your app data
  folder; never store it in `localStorage`.
* Package with `electron-builder` (NSIS/MSI, dmg, AppImage/deb); sign and notarise for distribution.

### Flutter desktop (Windows · macOS · Linux)

```bash
cd apps
flutter create desktop --platforms windows,macos,linux --project-name desktop_app
cd desktop && flutter pub add http flutter_secure_storage
flutter run -d windows        # or macos / linux;  build: flutter build windows|macos|linux
```

Use the same [Dart client](#flutter-android--ios--web) as mobile (default URL `http://127.0.0.1:8000/api`). Native desktop
apps have no CORS. Enable your OS's build prerequisites (`flutter doctor` lists them).

## Mobile-based clients

Native or cross-platform apps for Android and iOS: [Flutter](#flutter-android--ios--web) below, plus the
[other stacks](#other-stacks-vue-angular-svelte-react-native-swift-kotlin-) (React Native, Swift, Kotlin). No CORS; use
HTTPS in production and the platform keystore for the token.

## Flutter (Android · iOS · web)

```bash
cd apps
flutter create mobile --platforms android,ios,web --project-name mobile_app
cd mobile
flutter pub add http flutter_secure_storage
```

1. Nx (optional) — `apps/mobile/project.json`. Use `run`, not `serve`, so `nestlaravel dev` doesn't launch a device:
   ```json
   {
     "name": "mobile", "projectType": "application", "sourceRoot": "apps/mobile/lib", "tags": ["type:client", "stack:flutter"],
     "targets": {
       "run":  { "executor": "nx:run-commands", "options": { "command": "flutter run -d chrome --web-port 8090", "cwd": "apps/mobile" } },
       "test": { "executor": "nx:run-commands", "options": { "command": "flutter test", "cwd": "apps/mobile" } },
       "lint": { "executor": "nx:run-commands", "options": { "command": "flutter analyze", "cwd": "apps/mobile" } },
       "build": { "executor": "nx:run-commands", "options": { "command": "flutter build web --release", "cwd": "apps/mobile" } },
       "build:apk": { "executor": "nx:run-commands", "options": { "command": "flutter build apk --release", "cwd": "apps/mobile" } }
     }
   }
   ```
2. Gateway address (no CORS needed for native apps):

   | Where the app runs | URL |
   |--------------------|-----|
   | Android emulator | `http://10.0.2.2:8000/api` |
   | iOS simulator / desktop | `http://127.0.0.1:8000/api` |
   | Physical phone | your computer's LAN IP (`http://192.168.x.x:8000/api`), gateway started with `--host=0.0.0.0` |
   | Production | `https://api.yourdomain.com/api` (HTTPS only) |

   Pass it at run/build time, not in source: `flutter run --dart-define=API_URL=http://10.0.2.2:8000/api` and read it with
   `const String.fromEnvironment('API_URL')`.
3. Client — `lib/api_client.dart` (token in the platform keystore, not plain preferences):
   ```dart
   import 'dart:convert';
   import 'package:flutter_secure_storage/flutter_secure_storage.dart';
   import 'package:http/http.dart' as http;

   class ApiClient {
     ApiClient({String? baseUrl}) : baseUrl = baseUrl ?? const String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8000/api');
     final String baseUrl;
     final _store = const FlutterSecureStorage();

     Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body]) async {
       final token = await _store.read(key: 'access_token');
       final req = http.Request(method, Uri.parse('$baseUrl$path'))
         ..headers.addAll({'Accept': 'application/json', if (body != null) 'Content-Type': 'application/json', if (token != null) 'Authorization': 'Bearer $token'});
       if (body != null) req.body = jsonEncode(body);
       final res = await http.Response.fromStream(await req.send().timeout(const Duration(seconds: 15)));
       final json = jsonDecode(res.body) as Map<String, dynamic>;
       if (res.statusCode == 401) await _store.delete(key: 'access_token');
       if (res.statusCode >= 400) throw Exception(json['message'] ?? 'Request failed (${res.statusCode})');
       return json;
     }

     Future<void> login(String email, String password) async {
       final data = (await _send('POST', '/auth/login', {'email': email, 'password': password}))['data'];
       await _store.write(key: 'access_token', value: data['token'] as String);
     }

     Future<Map<String, dynamic>> me() async => (await _send('GET', '/auth/me'))['data'];
     Future<Map<String, dynamic>> get(String path) => _send('GET', path); // e.g. '/v1/orders/42'
   }
   ```
4. Android release builds must use HTTPS (cleartext is blocked by default). For local HTTP testing add
   `android:usesCleartextTraffic="true"` to the **debug** manifest only. iOS: keep App Transport Security on for production.
5. Flutter **web** is a browser app: give it a fixed port (`--web-port 8090`) and add `http://localhost:8090` to
   `CORS_ALLOWED_ORIGINS`.
6. Run `flutter test`, `flutter analyze`; ship with `flutter build apk|appbundle|ios|web`.

## Other stacks (Vue, Angular, Svelte, React Native, Swift, Kotlin, …)

Same five steps: scaffold with the official tool → optional `project.json` → set the base URL through the stack's env
mechanism → allow the origin (web only) → `POST /auth/login`, store the token, send `Authorization: Bearer …`.
Mobile: store the token in Keychain (iOS) / Keystore or EncryptedSharedPreferences (Android) / `react-native-keychain`.

## Production checklist

* [ ] Gateway is served over **HTTPS**; `CORS_ALLOWED_ORIGINS` lists only your real frontend origins.
* [ ] No secrets in the client (anything in a browser bundle or APK is public). Only public URLs/IDs.
* [ ] Web tokens: prefer an `httpOnly` cookie via a server/BFF; otherwise `sessionStorage` (never `localStorage`), strict CSP, no `innerHTML` with API data.
* [ ] Handle `401` (redirect to login), `422` (show `errors` per field) and `429` (back off).
* [ ] Static/SPAs: build and upload to any static host or CDN. Next.js: `next build` → Node host, Docker, or a platform of your choice.
* [ ] Mobile: signed release builds, certificate pinning only if you can operate the rotation, crash reporting.
* [ ] Add the app's build to CI (`nx build <name>`), and its tests (`nx test <name>`).
