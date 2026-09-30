import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { CliError } from './ui.js';

const isWin = process.platform === 'win32';

/** npm/npx/nx/composer are .cmd/.bat shims on Windows and must go through cmd.exe. */
function needsShell(command) {
  return isWin && /^(npm|npx|nx|pnpm|yarn|composer)$/i.test(command);
}

/** Quote one argument for cmd.exe. Refuses characters cmd.exe would expand or split on. */
function quoteWin(arg) {
  const value = String(arg);
  if (/[\r\n\0%]/.test(value)) {
    throw new CliError(`Refusing to pass argument with unsafe characters to the shell: ${JSON.stringify(value)}`);
  }
  if (/^[\w@+=:,./\\-]+$/.test(value)) return value;
  return `"${value.replace(/"/g, '""')}"`;
}

function spawnArgs(command, args) {
  return needsShell(command)
    ? [[command, ...args].map(quoteWin).join(' '), [], { shell: true }]
    : [command, args, { shell: false }];
}

/**
 * Run a command and stream its output. Rejects with CliError on non-zero exit.
 * On POSIX, arguments are never interpolated into a shell string.
 */
export function run(command, args = [], options = {}) {
  const { cwd = process.cwd(), env = {}, silent = false, allowFailure = false } = options;
  const [cmd, cmdArgs, extra] = spawnArgs(command, args);

  return new Promise((resolve, reject) => {
    const child = spawn(cmd, cmdArgs, {
      cwd,
      env: { ...process.env, ...env },
      stdio: silent ? ['ignore', 'pipe', 'pipe'] : 'inherit',
      windowsHide: true,
      ...extra,
    });

    let stdout = '';
    let stderr = '';
    child.stdout?.on('data', (d) => (stdout += d));
    child.stderr?.on('data', (d) => (stderr += d));

    child.on('error', (error) => {
      if (allowFailure) return resolve({ code: 127, stdout, stderr: String(error.message) });
      reject(new CliError(`Failed to start "${command}": ${error.message}`));
    });
    child.on('close', (code) => {
      if (code !== 0 && !allowFailure) {
        const tail = silent ? `\n${(stderr || stdout).trim().split('\n').slice(-15).join('\n')}` : '';
        return reject(new CliError(`"${command} ${args.join(' ')}" exited with code ${code}.${tail}`, code ?? 1));
      }
      resolve({ code: code ?? 0, stdout, stderr });
    });
  });
}

/** Synchronously capture a command's stdout; returns null if it cannot run. */
export function capture(command, args = [], options = {}) {
  const [cmd, cmdArgs, extra] = spawnArgs(command, args);
  const result = spawnSync(cmd, cmdArgs, {
    cwd: options.cwd,
    encoding: 'utf8',
    windowsHide: true,
    timeout: options.timeout ?? 15000,
    ...extra,
  });
  if (result.error || result.status !== 0) return null;
  return `${result.stdout ?? ''}`.trim();
}

/**
 * Run the workspace's own Nx (node_modules/nx/bin/nx.js) with the current Node.
 * Deliberately not `npx`: a nested npx inherits the parent npx's package settings
 * (npm_config_package) and would try to fetch/skip the wrong package.
 */
export function nx(args, options = {}) {
  const cwd = options.cwd ?? process.cwd();
  const pkgFile = join(cwd, 'node_modules', 'nx', 'package.json');
  if (!existsSync(pkgFile)) {
    throw new CliError('Nx is not installed in this workspace. Run: npm install');
  }
  // The bin path moved between Nx majors (bin/nx.js → dist/bin/nx.js): always read it from package.json.
  const pkg = JSON.parse(readFileSync(pkgFile, 'utf8'));
  const rel = typeof pkg.bin === 'string' ? pkg.bin : pkg.bin?.nx;
  const bin = rel ? join(cwd, 'node_modules', 'nx', rel) : null;
  if (!bin || !existsSync(bin)) {
    throw new CliError('Could not locate the Nx binary. Re-run: npm install');
  }
  return run(process.execPath, [bin, ...args], options);
}
