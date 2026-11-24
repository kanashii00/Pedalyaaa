# syntax=docker/dockerfile:1
#
# Runtime image for Pedalya on Render connected to Aiven MySQL.
#
# There is no Node build step: public/css and public/js are committed built
# assets, so composer install is the only build the image needs.

FROM php:8.3-apache

# Dependencies for Laravel, PostgreSQL, and local SQLite-based tests.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        default-libmysqlclient-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libsqlite3-dev \
        libicu-dev \
        pkg-config \
        supervisor \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies first so a source-only change does not invalidate the vendor layer.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist

COPY . .
COPY docker/entrypoint.sh /usr/local/bin/pedalya-entrypoint
COPY docker/supervisord.conf /etc/supervisor/conf.d/pedalya.conf

# .env is never part of the image: Render injects configuration as real
# environment variables, and a committed file would both shadow them and ship
# whatever secrets were left on a developer machine.
RUN rm -f .env

# Laravel needs these runtime paths to exist, even when they are empty in the
# source tree. Their contents are build artefacts and must not be baked in.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && php artisan storage:link \
    && chmod +x /usr/local/bin/pedalya-entrypoint \
    && rm -f storage/logs/*.log storage/framework/cache/data/* \
        storage/framework/sessions/* storage/framework/views/* \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Serve the application out of public/, not the project root, so .env, tests and
# the rest of the source stay unreachable over HTTP.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
    && sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' \
        /etc/apache2/sites-available/*.conf \
    && printf '%s\n' \
        'ServerName localhost' \
        '<Directory ${APACHE_DOCUMENT_ROOT}>' \
        '    Options -Indexes +FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/pedalya.conf \
    && a2enconf pedalya

ENV APP_ENV=production \
    APP_DEBUG=false

EXPOSE 10000

CMD ["/usr/local/bin/pedalya-entrypoint"]
