import { randomBytes } from 'node:crypto';

/** Laravel-compatible APP_KEY (AES-256-CBC/GCM): base64 of 32 random bytes. */
export const appKey = () => `base64:${randomBytes(32).toString('base64')}`;

/** URL/env-safe random secret (hex), 2 chars per byte. */
export const secret = (bytes = 32) => randomBytes(bytes).toString('hex');

/**
 * Set KEY=value in .env-style text. Replaces an existing (possibly commented,
 * empty) assignment or appends. Values with spaces/#/quotes are double-quoted.
 */
export function setEnv(text, key, value) {
  const rendered = /[\s#"'$\\]/.test(String(value)) ? `"${String(value).replace(/(["\\$])/g, '\\$1')}"` : String(value);
  const line = `${key}=${rendered}`;
  const pattern = new RegExp(`^#?\\s*${key}=.*$`, 'm');
  // Prefer an active assignment over a commented example.
  const active = new RegExp(`^${key}=.*$`, 'm');
  if (active.test(text)) return text.replace(active, () => line);
  if (pattern.test(text)) return text.replace(pattern, () => line);
  return `${text.replace(/\s*$/, '')}\n${line}\n`;
}

export function getEnv(text, key) {
  const match = text.match(new RegExp(`^${key}=(.*)$`, 'm'));
  return match ? match[1].replace(/^"|"$/g, '') : undefined;
}
