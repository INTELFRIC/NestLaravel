import { parseArgs } from './args.js';
import { add, ADD_HELP } from './commands/add.js';
import { create, CREATE_HELP } from './commands/create.js';
import { update, UPDATE_HELP } from './commands/update.js';
import { build, dev, lint, test } from './commands/tasks.js';
import { checkRequirements, printRequirements } from './doctor.js';
import { generateKafkaEvent, generateKafkaTopic } from './generators/kafka.js';
import { generateService } from './generators/service.js';
import { c, CliError, log } from './ui.js';
import { FRAMEWORK_VERSION, RUNTIME } from './versions.js';
import { requireWorkspace } from './workspace.js';

const HELP = `
${c.bold('NestLaravel')} ${FRAMEWORK_VERSION} — Nx + Laravel microservices + Kafka

${c.bold('Usage')}  nestlaravel <command> [options]

${c.bold('Project')}
  create <name>                       Create a new workspace         (npx nestlaravel create my-app)
  generate service <name>             New Laravel microservice        (wired into gateway, Nx, Docker)
  generate kafka-event <type>         New domain event                (--service <svc> [--consumer])
  generate kafka-topic <name>         Register a Kafka topic          (--service <svc> [--create])
  add tenancy                         Install the multi-tenancy package

${c.bold('Develop')}
  dev                                 Start Kafka/Postgres/Redis (Docker) and serve every app
                                      (--no-infra to skip Docker, --docker for the full containerised stack)
  test | lint                         Run via Nx across all apps     (--affected, --project <name>)
  build                               Build production Docker images (--tag, --registry)

${c.bold('Maintain')}
  doctor                              Check Node/PHP/Composer/Docker
  update                              Upgrade the workspace safely   (--dry-run first)

Run "nestlaravel <command> --help" for details. Requires Node >= ${RUNTIME.node.min}, PHP >= ${RUNTIME.php.min}, Composer >= ${RUNTIME.composer.min}.
`;

const GENERATE_HELP = `
Usage: nestlaravel generate <generator> <name> [options]

  service <name> [--port N] [--force] [--skip-install]
  kafka-event <domain.entity.action> --service <svc> [--consumer] [--force]
  kafka-topic <name> --service <svc> [--create] [--partitions N]

Aliases: g, gen. Service names are lowercase (e.g. payments); "users" works too.
`;

export async function main(argv) {
  const [command, ...rest] = argv;

  switch (command) {
    case undefined:
    case 'help':
    case '--help':
    case '-h':
      console.log(HELP);
      return 0;

    case '--version':
    case '-v':
    case 'version':
      console.log(FRAMEWORK_VERSION);
      return 0;

    case 'create':
    case 'new':
      await create(rest);
      return 0;

    case 'generate':
    case 'gen':
    case 'g': {
      const { positionals, flags } = parseArgs(rest, { boolean: ['force', 'consumer', 'create', 'skip-install', 'help'], alias: { h: 'help' } });
      const [what, name] = positionals;
      if (flags.help || !what) {
        console.log(GENERATE_HELP);
        return 0;
      }
      const root = requireWorkspace();
      if (!name) throw new CliError(`Missing name. Example: nestlaravel generate ${what} ${what === 'service' ? 'payments' : what === 'kafka-event' ? 'user.created --service users' : 'user-events --service users'}`);
      if (what === 'service') await generateService(root, name, flags);
      else if (what === 'kafka-event') await generateKafkaEvent(root, name, flags);
      else if (what === 'kafka-topic') await generateKafkaTopic(root, name, flags);
      else throw new CliError(`Unknown generator "${what}". Available: service, kafka-event, kafka-topic`);
      return 0;
    }

    case 'add':
      await add(rest);
      return 0;

    case 'dev':
    case 'serve':
      await dev(rest);
      return 0;

    case 'build':
      await build(rest);
      return 0;

    case 'test':
      await test(rest);
      return 0;

    case 'lint':
      await lint(rest);
      return 0;

    case 'update':
    case 'upgrade':
      await update(rest);
      return 0;

    case 'doctor': {
      log.info(`\n${c.bold('NestLaravel doctor')}\n`);
      const failed = printRequirements(checkRequirements({ needDocker: false }));
      log.blank();
      if (failed.length) {
        log.error(`${failed.length} required check(s) failed.`);
        return 1;
      }
      log.ok('Your machine is ready for NestLaravel.');
      return 0;
    }

    default:
      log.error(`Unknown command "${command}".`);
      console.log(HELP);
      return 1;
  }
}

export { CREATE_HELP, UPDATE_HELP, ADD_HELP };
