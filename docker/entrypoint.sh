#!/bin/sh
set -e

mkdir -p /app/app/data /app/app/upload /app/app/avatars /run/nginx
chown -R www-data:www-data /app/app/data /app/app/upload /app/app/avatars

php-fpm -D

term_handler() {
    nginx -s quit 2>/dev/null || true
    pkill -TERM php-fpm 2>/dev/null || true
    exit 0
}
trap term_handler TERM INT

nginx -g 'daemon off;' &
wait $!
