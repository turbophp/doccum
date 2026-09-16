# syntax=docker/dockerfile:1

# ---- minio: the binary only, copied into the app stage below ----
# dl.min.io returns HTTP 410 and the GitHub release carries no assets, so the
# binary is copied from the official image instead of downloaded. It is
# statically linked, so it runs in this Debian base unchanged. Pinned to a
# release tag -- never :latest -- so a rebuild always produces the same
# binary.
FROM quay.io/minio/minio:RELEASE.2025-09-07T16-13-09Z AS minio

# ---- base: runtime plus the binaries text extraction needs (spec §7) ----
FROM serversideup/php:8.4-frankenphp-bookworm AS base
ARG WITH_OFFICE=false
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng supervisor \
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

# Embedded object storage: the binary is copied, not downloaded (see the
# `minio` stage above); `mc` is deliberately not copied -- the AWS SDK already
# present creates the bucket, and `mc` would add ~30 MB for nothing.
COPY --from=minio /usr/bin/minio /usr/local/bin/minio
COPY docker/bin/doccum-minio /usr/local/bin/doccum-minio
COPY docker/supervisor/doccum.conf /etc/supervisor/conf.d/doccum.conf
RUN chmod +x /usr/local/bin/doccum-minio

USER www-data

ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true \
    PHP_OPCACHE_ENABLE=1

# Everything on by default, so `docker run -v doccum:/data -p 8080:8080 <image>`
# is a complete instance: web, both queue workers, the scheduler and object
# storage. Compose turns the workers off on its app service because dedicated
# containers run them there.
# The starter kit renders config('app.name') into every page title, so without
# this a fresh install is branded "Laravel".
ENV APP_NAME=doccum

ENV DOCCUM_EMBEDDED_STORAGE=true \
    DOCCUM_RUN_WORKERS=true \
    DOCCUM_RUN_SCHEDULER=true

# supervisord replaces frankenphp as PID 1's command, but serversideup's own
# entrypoint still runs first and ends with `exec "$@"` -- so every
# entrypoint.d script (migrations, storage link, our own credential
# generation) still runs before this starts.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/doccum.conf"]
