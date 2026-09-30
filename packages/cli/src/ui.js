const useColor = process.stdout.isTTY && !process.env.NO_COLOR;
const wrap = (open, close) => (text) => (useColor ? `\u001b[${open}m${text}\u001b[${close}m` : String(text));

export const c = {
  bold: wrap(1, 22),
  dim: wrap(2, 22),
  red: wrap(31, 39),
  green: wrap(32, 39),
  yellow: wrap(33, 39),
  cyan: wrap(36, 39),
};

export const log = {
  info: (m) => console.log(m),
  step: (m) => console.log(`${c.cyan('›')} ${m}`),
  ok: (m) => console.log(`${c.green('✔')} ${m}`),
  warn: (m) => console.warn(`${c.yellow('!')} ${m}`),
  error: (m) => console.error(`${c.red('✖')} ${m}`),
  blank: () => console.log(''),
  cmd: (m) => console.log(`  ${c.dim('$')} ${c.bold(m)}`),
};

export class CliError extends Error {
  constructor(message, exitCode = 1) {
    super(message);
    this.exitCode = exitCode;
  }
}
