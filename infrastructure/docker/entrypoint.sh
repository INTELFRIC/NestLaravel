#!/usr/bin/env sh
set -eu

cd /var/www/html

# Ensure runtime directories exist and are writable
mkdir -p \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache \
  /var/log/supervisor

# Prefer www-data when present (FPM); ignore failures under volume mounts
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

# Dev bind-mounts often leave an empty vendor volume — install if needed
if [ ! -f vendor/autoload.php ] && [ -f composer.json ] && command -v composer >/dev/null 2>&1; then
  echo "vendor/ missing; running composer install..."
  composer install --prefer-dist --no-interaction --no-progress || true
fi

# Optional wait for Postgres (skip if DB_HOST unset or WAIT_FOR_DB=0)
if [ "${WAIT_FOR_DB:-1}" = "1" ] && [ -n "${DB_HOST:-}" ] && [ "${DB_CONNECTION:-}" = "pgsql" ]; then
  echo "Waiting for Postgres at ${DB_HOST}:${DB_PORT:-5432}..."
  i=0
  until php -r "try { new PDO('pgsql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0); } catch (Throwable \$e) { exit(1); }" 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
      echo "Postgres is still unavailable after 60s; continuing anyway."
      break
    fi
    sleep 1
  done
fi

# Production optimisations (disabled in local/testing)
if [ "${APP_ENV:-production}" = "production" ] && [ "${SKIP_OPTIMIZE:-0}" != "1" ]; then
  if [ -n "${APP_KEY:-}" ]; then
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
  fi
fi

# Optional one-shot migrate (set RUN_MIGRATIONS=1 on app service)
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force --no-interaction || true
fi

exec "$@"
