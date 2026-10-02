#!/bin/sh
set -eu

: "${DB_DATABASE:?DB_DATABASE must be configured}"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost}}"

mkdir -p "$(dirname "$DB_DATABASE")"
touch "$DB_DATABASE"
chown www-data:www-data "$DB_DATABASE"

su -s /bin/sh www-data -c 'php artisan migrate --force --no-interaction'
su -s /bin/sh www-data -c 'php artisan config:cache'
su -s /bin/sh www-data -c 'php artisan route:cache'

exec supervisord -n -c /etc/supervisor/conf.d/pedalya.conf
