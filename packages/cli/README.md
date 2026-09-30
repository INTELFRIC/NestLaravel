# nestlaravel

The CLI for **NestLaravel** — Nx monorepo + Laravel microservices + Kafka.

```bash
npx nestlaravel create my-project
cd my-project
npx nestlaravel generate service orders
npx nestlaravel dev
```

Requires Node >= 20.11, PHP >= 8.3 (with `mbstring openssl pdo tokenizer xml ctype json fileinfo bcmath`),
Composer >= 2.6. Docker is recommended for Kafka/Postgres/Redis.

Run `npx nestlaravel --help` for all commands, or see the full documentation at https://nestlaravel.dev.

License: MIT
