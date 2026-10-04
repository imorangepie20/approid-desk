#!/bin/sh
set -eu

install -d -o www-data -g www-data \
    storage/app/private/attachments \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan package:discover --ansi
php artisan config:cache --ansi
php artisan view:cache --ansi

exec "$@"
