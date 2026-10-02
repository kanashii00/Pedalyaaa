#!/bin/sh
set -eu

: "${DB_DATABASE:?DB_DATABASE must be configured}"
: "${APP_KEY:?APP_KEY must be configured}"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost}}"

# Derive an accepted Laravel key when Render's generated value has an unsupported length.
APP_KEY="$(APP_KEY="$APP_KEY" php -r '$key = getenv("APP_KEY"); $decoded = str_starts_with($key, "base64:") ? base64_decode(substr($key, 7), true) : $key; if ($decoded !== false && in_array(strlen($decoded), [16, 32], true)) { echo $key; } else { echo "base64:".base64_encode(hash_hmac("sha256", "pedalya-app-key", $key, true)); }')"
export APP_KEY

mkdir -p "$(dirname "$DB_DATABASE")"
touch "$DB_DATABASE"
chown www-data:www-data "$DB_DATABASE"

su -s /bin/sh www-data -c 'php artisan migrate --force --no-interaction'
su -s /bin/sh www-data -c 'php artisan config:cache'
su -s /bin/sh www-data -c 'php artisan route:cache'

exec supervisord -n -c /etc/supervisor/conf.d/pedalya.conf
