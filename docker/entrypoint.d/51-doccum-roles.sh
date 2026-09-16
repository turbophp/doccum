#!/bin/sh
# Runs AFTER 50-laravel-automations.sh has migrated, so the tables exist.
#
# Roles and permissions are application structure: the installer assigns the
# admin role at the end of setup, and registration assigns the default role.
# Without this a fresh instance fails at the very last step of installation.
set -e

[ "${AUTORUN_ENABLED:-false}" = "true" ] || exit 0

php "${APP_BASE_DIR:-/var/www/html}/artisan" doccum:ensure-roles || \
    echo "⚠️  doccum: could not ensure roles; the installer will do it"
