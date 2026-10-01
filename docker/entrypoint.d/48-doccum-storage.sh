#!/bin/sh
set -e

[ "${DOCCUM_EMBEDDED_STORAGE:-false}" = "true" ] || exit 0

ROOT="${DOCCUM_EMBEDDED_ROOT:-/data/objects}"
ENV_FILE=/data/storage.env
IAM_DIR="${DOCCUM_STORAGE_IAM_DIR:-/data/storage-iam}"
LEGACY_ENV_FILE=/data/minio.env

# A volume carrying /data/minio.env was initialised by a doccum that embedded
# MinIO. Its objects are in MinIO's on-disk layout, which versitygw's posix
# backend does not read: booting anyway would present an instance whose every
# stored document had vanished, with the bytes still on the volume. Refuse
# instead, and say what to do. Nothing here deletes or rewrites that file --
# the operator's data is left exactly as it was found (decision/0073).
if [ -f "$LEGACY_ENV_FILE" ] && [ ! -f "$ENV_FILE" ]; then
    if [ "${DOCCUM_STORAGE_ALLOW_LEGACY_DATA:-false}" != "true" ]; then
        echo "✋ doccum: this volume was created by a release that embedded MinIO." >&2
        echo "   The embedded object store is now versitygw, which stores objects as" >&2
        echo "   plain files and cannot read MinIO's layout. Your data is untouched at" >&2
        echo "   ${ROOT}." >&2
        echo "   Migration steps: docs/self-hosting/upgrading.md" >&2
        echo "   To start with an empty object store anyway and keep the old bytes on" >&2
        echo "   disk, set DOCCUM_STORAGE_ALLOW_LEGACY_DATA=true." >&2
        exit 1
    fi
    echo "⚠️  doccum: DOCCUM_STORAGE_ALLOW_LEGACY_DATA=true -- starting versitygw against ${ROOT} without reading MinIO's layout"
fi

mkdir -p "$ROOT" "$IAM_DIR"

if [ ! -f "$ENV_FILE" ]; then
    # The names versitygw itself reads for its root account.
    USER_VALUE="doccum"
    PASS_VALUE="$(php -r 'echo bin2hex(random_bytes(24));')"
    TMP="${ENV_FILE}.$$"
    {
        printf 'ROOT_ACCESS_KEY=%s\n' "$USER_VALUE"
        printf 'ROOT_SECRET_KEY=%s\n' "$PASS_VALUE"
        printf 'DOCCUM_EMBEDDED_ROOT=%s\n' "$ROOT"
        printf 'DOCCUM_STORAGE_IAM_DIR=%s\n' "$IAM_DIR"
    } > "$TMP"
    chmod 600 "$TMP"
    mv "$TMP" "$ENV_FILE"
    echo "🔐 doccum: generated embedded storage credentials"
fi
