# syntax=docker/dockerfile:1

# ---- base: runtime plus the binaries text extraction needs (spec §7) ----
FROM serversideup/php:8.4-frankenphp-bookworm AS base
ARG WITH_OFFICE=false
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng \
 && if [ "$WITH_OFFICE" = "true" ]; then \
      apt-get install -y --no-install-recommends libreoffice-core libreoffice-writer libreoffice-calc; \
    fi \
 && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_sqlite pdo_pgsql pdo_mysql intl gd zip bcmath exif
# Created here so the named volume inherits this ownership on first use: Docker
# seeds an empty named volume from the image, permissions included.
RUN mkdir -p /data && chown www-data:www-data /data
USER www-data

# ---- vendor: dependencies and the optimised autoloader ----
FROM base AS vendor
WORKDIR /app
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# ---- assets ----
# vendor/ is required here, not optional: resources/css/app.css imports
# ../../vendor/livewire/flux/dist/flux.css and @sources vendor stub paths, so a
# Tailwind build without it fails on the missing import.
FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- app ----
FROM base AS app
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

USER root
COPY docker/entrypoint.d/ /etc/entrypoint.d/
RUN chmod +x /etc/entrypoint.d/*.sh
USER www-data

ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true \
    PHP_OPCACHE_ENABLE=1
