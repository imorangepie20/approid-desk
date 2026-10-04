FROM composer:2 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-req=ext-*

FROM node:24-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci
COPY --from=dependencies /app/vendor ./vendor
COPY resources ./resources
COPY public ./public
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY routes ./routes
RUN npm run build

FROM php:8.4-fpm-bookworm AS application

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        nginx \
        supervisor \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring opcache pcntl pdo_mysql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
COPY --from=dependencies /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-approid-desk.ini
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/approid-desk.conf
COPY docker/production/entrypoint.sh /usr/local/bin/approid-desk-entrypoint

RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts \
    && chmod 0755 /usr/local/bin/approid-desk-entrypoint \
    && mkdir -p storage/app/private/attachments storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["approid-desk-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
