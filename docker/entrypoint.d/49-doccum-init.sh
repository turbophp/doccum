#!/bin/sh
# doccum first-boot initialisation.
#
# Numbered 49 so it runs before 50-laravel-automations.sh, which migrates: by
# then the database file must exist and APP_KEY must be resolvable.
#
# The image sources each entrypoint script in a SUBSHELL -- `(. "$f")` -- so
# exported variables do not survive into the application process. Everything
# here must therefore persist to disk, never to the environment.

set -e

DATA_DIR="${DOCCUM_DATA_DIR:-/data}"
ENV_FILE="${DATA_DIR}/.env"
SQLITE_FILE="${DB_DATABASE:-${DATA_DIR}/doccum.sqlite}"

# 1. SQLite needs its file to exist first: `migrate` will not create one
#    non-interactively, it just fails.
case "$SQLITE_FILE" in
    *.sqlite|*.sqlite3)
        [ -f "$SQLITE_FILE" ] || touch "$SQLITE_FILE"
        ;;
esac

# 2. APP_KEY, generated once and kept on the data volume so that recreating a
#    container never invalidates existing sessions or encrypted columns.
#    Laravel's Dotenv is immutable, so a real APP_KEY from the environment
#    still wins over this file.
if [ -z "${APP_KEY}" ]; then
    if [ ! -f "$ENV_FILE" ]; then
        KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        TMP="${ENV_FILE}.$$"
        printf 'APP_KEY=%s\n' "$KEY" > "$TMP"
        # Atomic: four containers can reach this line at the same moment.
        mv "$TMP" "$ENV_FILE"
        echo "🔑 doccum: generated a new APP_KEY at ${ENV_FILE}"
    fi
    ln -sf "$ENV_FILE" "${APP_BASE_DIR:-/var/www/html}/.env"
fi
