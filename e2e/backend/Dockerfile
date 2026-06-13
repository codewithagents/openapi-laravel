# syntax=docker/dockerfile:1
#
# e2e demo backend: the GENERATED Laravel app, served over real HTTP.
#
# Key insight that makes this image self-contained: codewithagents/openapi-laravel
# is a GENERATION-TIME tool (the `openapi:generate` artisan command). It is NOT a
# runtime dependency. The committed app/Data classes depend only on
# spatie/laravel-data, and the controllers/routes are plain Laravel. So the
# package is in require-dev (behind a composer PATH repo that only exists in the
# local checkout), and the image runs `composer install --no-dev`, which never
# needs that path. The container runs the committed generated code as-is.

FROM php:8.3-cli AS base

# Laravel runtime extensions. php:8.3-cli already bundles ctype, dom, fileinfo,
# mbstring, pdo, pdo_sqlite, tokenizer, xml and sqlite3, so we only build the
# three Laravel wants that are missing: bcmath, intl and zip. The demo is
# file-backed (no DB server); pdo_sqlite comes for free and keeps the
# framework's optional sqlite paths from ever fataling.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" bcmath intl zip; \
    rm -rf /var/lib/apt/lists/*

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first for layer caching. Only the manifest is needed; the
# path repo target (../..) is absent in the build context on purpose, and
# --no-dev means the dev-only generator package is never resolved.
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist

# Now the application code (generated Data classes, controllers, routes, glue).
COPY . .

# Ship the container env, finish the autoloader, and generate an APP_KEY at
# build time so every container has a stable key without a runtime step.
RUN set -eux; \
    cp .env.docker .env; \
    composer dump-autoload --no-dev --optimize; \
    php artisan package:discover --ansi; \
    php artisan key:generate --force; \
    php artisan config:clear

# Writable runtime dirs + the file-backed store location. storage/app holds
# petstore.json (read-modify-write on every mutation); storage/app/public/uploads
# holds uploaded images served through the public symlink.
RUN set -eux; \
    mkdir -p storage/app storage/app/public/uploads storage/framework/cache/data \
             storage/framework/sessions storage/framework/views \
             storage/logs bootstrap/cache; \
    chmod -R ug+rwx storage bootstrap/cache

EXPOSE 8088

# On container start: link public/storage -> storage/app/public so uploaded
# images are reachable at /storage/uploads/<file>, seed the deterministic store
# (Rex #1, Whiskers #2), then serve. Doing this at runtime (not build) keeps
# every fresh `up` reproducible.
CMD ["sh", "-c", "php artisan storage:link --force && php artisan petstore:reset && php artisan serve --host=0.0.0.0 --port=8088"]
