# nestlaravel

The CLI for **NestLaravel** — Nx monorepo + Laravel microservices + Kafka.

```bash
npx nestlaravel create my-project
cd my-project
npx nestlaravel generate service orders
npx nestlaravel dev
```

Add clients to a workspace (they start with `nestlaravel dev`, no extra terminals):

```bash
npx nestlaravel add portals                 # Next.js customer (:3000) + admin (:3001) portals
npx nestlaravel generate app web storefront # Next.js app on the next free port
npx nestlaravel generate app mobile         # Flutter app (served as web on :8090; `nx run mobile:device` for a phone)
```

Requires Node >= 20.11, PHP >= 8.3 (with `mbstring openssl pdo tokenizer xml ctype json fileinfo bcmath`),
Composer >= 2.6. Docker is recommended for Kafka/Postgres/Redis.

Run `npx nestlaravel --help` for all commands, or see the full documentation at https://nestlaravel.intelfric.com.

License: MIT
