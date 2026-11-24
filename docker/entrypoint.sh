#!/bin/sh
set -eu

: "${APP_KEY:?APP_KEY must be configured}"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost}}"

# Aiven supplies a CA certificate. Keep it only in Render's secret store and
# materialize it in the container at boot; it is never copied into the image.
if [ -n "${MYSQL_SSL_CA_BASE64:-}" ]; then
    umask 077
    printf '%s' "$MYSQL_SSL_CA_BASE64" | base64 -d > /tmp/aiven-ca.pem
    chown www-data:www-data /tmp/aiven-ca.pem
    chmod 0400 /tmp/aiven-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/aiven-ca.pem
fi

# Schema changes are intentionally opt-in. Production migrations must be
# reviewed, backed up and run as a one-off Render Shell/release action, never
# on every application restart.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    su -s /bin/sh www-data -c 'php artisan migrate --force --no-interaction'
fi

su -s /bin/sh www-data -c 'php artisan config:cache'
su -s /bin/sh www-data -c 'php artisan route:cache'

exec supervisord -n -c /etc/supervisor/conf.d/pedalya.conf
