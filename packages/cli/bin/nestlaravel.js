#!/usr/bin/env node
import { main } from '../src/index.js';

main(process.argv.slice(2)).then(
  (code) => process.exit(code ?? 0),
  (error) => {
    process.stderr.write(`\nnestlaravel: ${error?.message ?? error}\n`);
    if (process.env.NESTLARAVEL_DEBUG && error?.stack) process.stderr.write(`${error.stack}\n`);
    process.exit(error?.exitCode ?? 1);
  },
);
