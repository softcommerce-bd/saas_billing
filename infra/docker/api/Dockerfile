# syntax=docker/dockerfile:1.7
# Laravel API image: php-fpm for HTTP, same image runs queue worker and scheduler.

FROM composer:2 AS vendor
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY apps/api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

FROM php:8.3-fpm-alpine AS runtime
RUN apk add --no-cache icu-libs libpq libzip \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl pcntl opcache bcmath zip \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps

COPY infra/docker/api/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY infra/docker/api/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www/api
COPY --from=vendor --chown=www-data:www-data /app /var/www/api
RUN rm -f .env && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]
