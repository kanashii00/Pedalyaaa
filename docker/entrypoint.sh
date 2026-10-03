#!/bin/sh
set -eu

: "${APP_KEY:?APP_KEY must be configured}"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost}}"

# SQLite needs a local database file; network databases provide their own storage.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    : "${DB_DATABASE:?DB_DATABASE must be configured for SQLite}"
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
    chown www-data:www-data "$DB_DATABASE"
fi

# Derive an accepted Laravel key when Render's generated value has an unsupported length.
APP_KEY="$(APP_KEY="$APP_KEY" php -r '$key = getenv("APP_KEY"); $decoded = str_starts_with($key, "base64:") ? base64_decode(substr($key, 7), true) : $key; if ($decoded !== false && in_array(strlen($decoded), [16, 32], true)) { echo $key; } else { echo "base64:".base64_encode(hash_hmac("sha256", "pedalya-app-key", $key, true)); }')"
export APP_KEY

su -s /bin/sh www-data -c 'php artisan migrate --force --no-interaction'

# Re-seed the reference data on each boot to ensure the demo has a fleet and
# settings to show. Only the two safe seeders run here:
# AdminSeeder and RiderSeeder are deliberately left out because they hardcode a
# shared password.
su -s /bin/sh www-data -c 'php artisan db:seed --class=SystemSettingsSeeder --force --no-interaction'
su -s /bin/sh www-data -c 'php artisan db:seed --class=BicycleSeeder --force --no-interaction'

# Demo logins are only provisioned when a password was supplied, so no credential
# is ever baked into the image. Set DEMO_ACCOUNT_PASSWORD once in the Render
# dashboard and the same admin/rider logins come back on every deploy.
if [ -n "${DEMO_ACCOUNT_PASSWORD:-}" ]; then
    su -s /bin/sh www-data -c 'php artisan demo:provision-accounts --no-interaction'
else
    echo "pedalya: DEMO_ACCOUNT_PASSWORD is unset, skipping demo account provisioning." >&2
fi

su -s /bin/sh www-data -c 'php artisan config:cache'
su -s /bin/sh www-data -c 'php artisan route:cache'

exec supervisord -n -c /etc/supervisor/conf.d/pedalya.conf
