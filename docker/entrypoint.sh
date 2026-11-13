#!/bin/sh
set -eu

PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*) echo 'PORT must be an integer.' >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo 'PORT must be between 1 and 65535.' >&2
    exit 1
fi
export PORT

# Secrets are injected at runtime, never generated or printed here.
: "${APP_KEY:?APP_KEY must be configured in the service environment}"
: "${DB_URI:?DB_URI must be configured in the service environment}"
: "${DB_DATABASE:?DB_DATABASE must be configured in the service environment}"

cd /var/www/adoptplace
mkdir -p storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R u=rwX,g=rX,o= storage bootstrap/cache

# Package discovery was deliberately omitted from Composer build hooks.
# These commands prepare local metadata only; no seeds, indexes or migrations.
php artisan package:discover --ansi --no-interaction
php artisan config:cache --no-interaction
chown -R www-data:www-data bootstrap/cache
chmod -R u=rwX,g=rX,o= bootstrap/cache

apache2ctl -t
exec "$@"
