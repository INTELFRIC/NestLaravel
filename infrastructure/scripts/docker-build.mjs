#!/usr/bin/env node
// Builds the production image for one Laravel app.
//
//   node infrastructure/scripts/docker-build.mjs apps/orders-service
//
// Env: REGISTRY (default "nestlaravel"), IMAGE_TAG (default "dev").
// Used by the Nx `build` target of every Laravel project (`npx nestlaravel build`).
import { spawnSync } from 'node:child_process';
import { basename } from 'node:path';

const appDir = process.argv[2];
if (!appDir || !/^apps\/[a-z0-9-]+$/.test(appDir)) {
  console.error('usage: docker-build.mjs apps/<name>');
  process.exit(2);
}

const registry = process.env.REGISTRY || 'nestlaravel';
const tag = process.env.IMAGE_TAG || 'dev';
const name = basename(appDir) === 'api' ? 'gateway' : basename(appDir);
const image = `${registry}/${name}:${tag}`;

const args = [
  'build',
  '-f', 'infrastructure/docker/laravel.Dockerfile',
  '--build-arg', `APP_DIR=${appDir}`,
  '-t', image,
  '.',
];

console.log(`> docker ${args.join(' ')}`);
const result = spawnSync('docker', args, { stdio: 'inherit' });
if (result.error) {
  console.error(`Could not run docker: ${result.error.message}`);
  process.exit(1);
}
process.exit(result.status ?? 1);
