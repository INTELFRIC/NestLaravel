import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

/** Version of this CLI / framework release (single source of truth: package.json). */
export const FRAMEWORK_VERSION = JSON.parse(
  readFileSync(join(here, '..', 'package.json'), 'utf8'),
).version;

/**
 * Supported runtime matrix. Keep in sync with docs/INSTALLATION.md and CI.
 * `min` is enforced by `doctor`/`create`; `tested` lists versions exercised in CI.
 */
export const RUNTIME = {
  node: { min: '20.11.0', tested: ['20', '22', '24'] },
  npm: { min: '10.0.0' },
  php: { min: '8.3.0', tested: ['8.3', '8.4'] },
  composer: { min: '2.6.0' },
  laravel: '^13.30',
  nx: '^23.2',
  kafka: '>=3.6 (KRaft; dev image apache/kafka 4.0)',
  redis: '>=7',
  postgres: '>=15 (dev image 16)',
  phpExtensions: ['mbstring', 'openssl', 'pdo', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'bcmath'],
};

/** Files/dirs that identify a NestLaravel workspace. */
export const MANIFEST = 'nestlaravel.json';
