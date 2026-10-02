#!/bin/sh
set -eu

: "${DB_DATABASE:?DB_DATABASE must be configured}"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost}}"

touch "$DB_DATABASE"
chown www-data:www-data "$DB_DATABASE"

php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache

exec supervisord -n -c /etc/supervisor/conf.d/pedalya.conf
