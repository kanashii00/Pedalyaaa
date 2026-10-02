#!/bin/bash

# Install dependencies
composer install --no-dev --optimize-autoloader

# Generate APP_KEY if not exists
if [ -z "$APP_KEY" ]; then
    php artisan key:generate
fi

# Cache config
php artisan config:cache

# Cache routes
php artisan route:cache

# Run migrations
php artisan migrate --force

# Collect static assets (if any)
php artisan storage:link || true

echo "Build complete!"
