#!/bin/sh
set -e

cd /app

mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

mkdir -p /run/php
chown www-data:www-data /run/php

if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
    chown -R www-data:www-data "$(dirname "$DB_DATABASE")"
fi

if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY is not set."
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
