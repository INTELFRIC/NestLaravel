name: CI
on:
  push: { branches: [main] }
  pull_request:

permissions:
  contents: read

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: npm }
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, intl, pdo_sqlite, bcmath, redis
          tools: composer:v2
      - run: npm ci
      - name: Install PHP dependencies
        run: |
          for app in apps/*/; do
            if [ -f "$app/composer.json" ]; then composer install --working-dir="$app" --no-interaction --prefer-dist; fi
          done
      - name: Prepare test env
        run: |
          for app in apps/*/; do
            if [ -f "$app/artisan" ]; then
              cp -n "$app/.env.example" "$app/.env" || true
              php "$app/artisan" key:generate --force
            fi
          done
      - run: npx nestlaravel test
      - name: Security audit
        run: |
          for app in apps/*/; do
            if [ -f "$app/composer.json" ]; then composer audit --working-dir="$app"; fi
          done
          npm audit --omit=dev --audit-level=high
