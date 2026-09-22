# syntax=docker/dockerfile:1

# ---- minio: the binary only, copied into the app stage below ----
# dl.min.io returns HTTP 410 and the GitHub release carries no assets, so the
# binary is copied from the official image instead of downloaded. It is
# statically linked, so it runs in this Debian base unchanged. Pinned to a
# release tag -- never :latest -- so a rebuild always produces the same
# binary.
FROM quay.io/minio/minio:RELEASE.2025-09-07T16-13-09Z AS minio

# ---- base: runtime plus the binaries text extraction needs (spec §7) ----
FROM serversideup/php:8.5-frankenphp-bookworm AS base
ARG WITH_OFFICE=false
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng supervisor \
 && if [ "$WITH_OFFICE" = "true" ]; then \
      apt-get install -y --no-install-recommends libreoffice-core libreoffice-writer libreoffice-calc; \
    fi \
 && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_sqlite pdo_pgsql pdo_mysql intl gd zip bcmath exif
# /data must EXIST and must be EMPTY in the image. Both halves are load-bearing
# and the second one is the fix for issue #385.
#
# It must exist so a fresh named volume inherits this ownership on first use:
# Docker seeds a new volume from the image path it is mounted over, permissions
# included.
#
# It must be empty because Docker seeds CONTENT the same way -- by copying the
# image's files at that path into the volume as the container starts -- and
# compose.yaml's four services all mount the same `db:` volume at /data and are
# started simultaneously. The base image ships /data/caddy (its XDG_DATA_HOME is
# /data), so on a fresh volume the daemon copied that directory for several
# containers at once and the loser died before it ever ran:
#
#   Error response from daemon: failed to mkdir
#   /var/lib/docker/volumes/doccum_db/_data/caddy: file exists
#
# Nothing in doccum's own startup was involved, and it is worth being exact
# about that because the first diagnosis was not: three of the four services
# override `command`, so they never start Caddy at all, and the mkdir that
# failed is the daemon's, not any entrypoint's.
#
# Emptying it strands nothing. Caddy recreates $XDG_DATA_HOME/caddy on demand
# (`InstanceID()` does `os.MkdirAll`; certmagic's FileStorage likewise), and
# with SSL_MODE=off -- the base image's default, which doccum never overrides
# and no doc tells anyone to -- the ssl-mode Caddyfile defines one plain `http`
# site with no `tls` directive and `auto_https off`, so Caddy issues no
# certificates and the only file it writes there is instance.uuid, which it
# regenerates when absent.
#
# tests.yml's "Refuse an image that ships content under /data" step fails the
# build if anything reintroduces content here.
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
FROM node:26-bookworm-slim AS assets
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

# Without this the database lands wherever config/database.php's fallback
# puts it -- database_path('database.sqlite'), inside the image layer -- and
# `docker run -v doccum:/data` loses every user, grant, file row and search
# index the moment the container is replaced, while objects and minio.env
# survive on the volume. The instance comes back half-alive rather than
# empty, which is worse. compose.yaml has always set this; the image never
# did, so the run command documented in CLAUDE.md was the broken one.
#
# entrypoint.d/49-doccum-init.sh already creates this exact path, and has
# been creating a file nothing then opened.
ENV DB_CONNECTION=sqlite \
    DB_DATABASE=/data/doccum.sqlite

# Declared this late on purpose: an ARG instruction busts build-cache for
# itself and every instruction after it in this stage, and nothing above this
# line -- installing dependencies, building assets, copying the app in --
# depends on the version, so a tag-only rebuild (the release workflow's
# normal case) still reuses every one of those layers. Only these last few
# metadata/env layers get rebuilt.
#
# Defaults to the same dev value config/doccum.php falls back to, so a plain
# `docker build .` with no --build-arg (a local `docker compose up --build`,
# or this repo's own CI image job) produces a container whose label, env var
# and config('doccum.version') all agree on 0.1.0-dev -- see
# .github/scripts/container-smoke.mjs's version-pill check.
ARG DOCCUM_VERSION=0.1.0-dev

# Reaches config('doccum.version') via env('DOCCUM_VERSION', ...) in
# config/doccum.php -- config:cache is never run in this image (see the
# comment on checkTopbar in container-smoke.mjs), so a plain env var read at
# request time is enough; no config rebuild is needed for this to take effect.
ENV DOCCUM_VERSION=${DOCCUM_VERSION}

# The external source the smoke test's version-pill check reads with `docker
# inspect`, independent of the PHP process entirely -- see
# item/version-from-tag. release.yml also gets org.opencontainers.image.version
# from docker/metadata-action based on the pushed tag; that label and this one
# are set from the same tag value on a real release (see release.yml), so they
# do not disagree there. This explicit LABEL is what makes the value present
# at all on a locally built image, which never goes through metadata-action.
LABEL org.opencontainers.image.version="${DOCCUM_VERSION}"

# supervisord replaces frankenphp as PID 1's command, but serversideup's own
# entrypoint still runs first and ends with `exec "$@"` -- so every
# entrypoint.d script (migrations, storage link, our own credential
# generation) still runs before this starts.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/doccum.conf"]
