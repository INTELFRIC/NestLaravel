/**
 * Minimal argv parser: positionals + --flag, --key=value, --key value, -y.
 * Boolean flags must be declared so `--yes foo` does not swallow `foo`.
 *
 * @param {string[]} argv
 * @param {{ boolean?: string[], alias?: Record<string,string> }} [spec]
 */
export function parseArgs(argv, spec = {}) {
  const booleans = new Set(spec.boolean ?? []);
  const alias = spec.alias ?? {};
  const positionals = [];
  const flags = {};
  const rest = [];

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];

    if (arg === '--') {
      rest.push(...argv.slice(i + 1));
      break;
    }

    if (arg.startsWith('--')) {
      const [rawKey, inline] = arg.slice(2).split(/=(.*)/s, 2);
      const key = alias[rawKey] ?? rawKey;

      if (rawKey.startsWith('no-')) {
        flags[alias[rawKey.slice(3)] ?? rawKey.slice(3)] = false;
      } else if (inline !== undefined) {
        flags[key] = inline;
      } else if (booleans.has(key)) {
        flags[key] = true;
      } else if (i + 1 < argv.length && !argv[i + 1].startsWith('-')) {
        flags[key] = argv[++i];
      } else {
        flags[key] = true;
      }
    } else if (arg.startsWith('-') && arg.length > 1) {
      for (const ch of arg.slice(1)) flags[alias[ch] ?? ch] = true;
    } else {
      positionals.push(arg);
    }
  }

  return { positionals, flags, rest };
}
