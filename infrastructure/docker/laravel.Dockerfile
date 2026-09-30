# syntax=docker/dockerfile:1
#
# Production image for ANY NestLaravel Laravel app (gateway or microservice).
# Build context = workspace root, so path packages (packages/*) are available:
#
#   docker build -f infrastructure/docker/laravel.Dockerfile \
#     --build-arg APP_DIR=apps/orders-service -t myorg/orders-service:1.0.0 .
#
# The same image runs web (default CMD), queue workers, the scheduler and Kafka
# consumers by overriding the command (see docker-compose.yml).

ARG PHP_VERSION=8.4

# -----------------------------------------------------------------------------
# Base: PHP-FPM + extensions (incl. ext-rdkafka for real Kafka connectivity)
# -----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_MIRROR_PATH_REPOS=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl unzip libpq-dev libzip-dev libicu-dev librdkafka-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql pgsql zip \
    && pecl install redis rdkafka \
    && docker-php-ext-enable redis rdkafka \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infrastructure/docker/php.ini /usr/local/etc/php/conf.d/99-nestlaravel.ini

# -----------------------------------------------------------------------------
# Vendor: production dependencies only
# -----------------------------------------------------------------------------
FROM base AS vendor

ARG APP_DIR=apps/api
WORKDIR /build/${APP_DIR}

COPY packages /build/packages
COPY ${APP_DIR}/composer.json ${APP_DIR}/composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --prefer-dist --no-interaction --no-progress

COPY ${APP_DIR}/ .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi || true \
    && rm -f .env

# -----------------------------------------------------------------------------
# Runtime: nginx + php-fpm under Supervisor
# -----------------------------------------------------------------------------
FROM base AS app

ARG APP_DIR=apps/api

RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor \
    && mkdir -p /var/log/supervisor \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY infrastructure/docker/nginx.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && sed -i 's/# server_tokens off;/server_tokens off;/' /etc/nginx/nginx.conf

COPY infrastructure/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY infrastructure/docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /build/${APP_DIR}/ /var/www/html/

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
