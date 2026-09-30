# syntax=docker/dockerfile:1
# Multi-stage production image: PHP 8.3-FPM + nginx (Supervisor).
# Same image powers app, worker, and scheduler via command overrides.

ARG PHP_VERSION=8.3

# -----------------------------------------------------------------------------
# Base: PHP-FPM + extensions
# -----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-laravel.ini

# -----------------------------------------------------------------------------
# Vendor: Composer install (production by default)
# -----------------------------------------------------------------------------
FROM base AS vendor

ARG COMPOSER_FLAGS="--no-dev --optimize-autoloader"

COPY composer.json composer.lock ./
RUN composer install ${COMPOSER_FLAGS} --no-scripts --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-interaction \
    && php artisan package:discover --ansi || true

# -----------------------------------------------------------------------------
# Development: includes require-dev + optional Xdebug
# -----------------------------------------------------------------------------
FROM base AS development

RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

RUN { \
      echo "xdebug.mode=develop,debug"; \
      echo "xdebug.start_with_request=trigger"; \
      echo "xdebug.client_host=host.docker.internal"; \
      echo "xdebug.client_port=9003"; \
    } > /usr/local/etc/php/conf.d/99-xdebug.ini \
    && { \
      echo "opcache.validate_timestamps=1"; \
      echo "opcache.revalidate_freq=0"; \
    } > /usr/local/etc/php/conf.d/99-opcache-dev.ini

COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-scripts --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-interaction \
    && php artisan package:discover --ansi || true \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# -----------------------------------------------------------------------------
# Production / app runtime: nginx + php-fpm via Supervisor
# -----------------------------------------------------------------------------
FROM base AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
    && mkdir -p /var/log/supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY docker/nginx/default.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
