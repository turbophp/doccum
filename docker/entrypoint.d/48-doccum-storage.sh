#!/bin/sh
set -e

[ "${DOCCUM_EMBEDDED_STORAGE:-false}" = "true" ] || exit 0

ROOT="${DOCCUM_EMBEDDED_ROOT:-/data/objects}"
ENV_FILE=/data/minio.env

mkdir -p "$ROOT"

if [ ! -f "$ENV_FILE" ]; then
    USER_VALUE="doccum"
    PASS_VALUE="$(php -r 'echo bin2hex(random_bytes(24));')"
    TMP="${ENV_FILE}.$$"
    {
        printf 'MINIO_ROOT_USER=%s\n' "$USER_VALUE"
        printf 'MINIO_ROOT_PASSWORD=%s\n' "$PASS_VALUE"
        printf 'DOCCUM_EMBEDDED_ROOT=%s\n' "$ROOT"
    } > "$TMP"
    chmod 600 "$TMP"
    mv "$TMP" "$ENV_FILE"
    echo "🔐 doccum: generated embedded storage credentials"
fi
