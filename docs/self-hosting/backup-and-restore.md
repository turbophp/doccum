# Backup and restore

## What actually has to be backed up

Everything doccum needs to keep is under one path: `/data`. `Dockerfile`
sets `DB_DATABASE=/data/doccum.sqlite` explicitly for exactly this reason —
without it, the database would land wherever `config/database.php`'s own
fallback puts it (inside the image layer), and `docker run -v doccum:/data`
would lose every user, grant, file row, and search index the moment the
container is replaced, while objects and `minio.env` survived on the
volume untouched. The instance would come back half-alive rather than
empty, which is worse than either extreme. Nothing under `storage/` needs
backing up — CLAUDE.md's Docker section is explicit that nothing there
survives a rebuild, by design, and nothing doccum relies on is stored
there.

Concretely, `/data` holds:

- **`doccum.sqlite`** (or nothing, if you moved to PostgreSQL/MySQL — see
  below) — every directory, file, version row, property, user, role,
  setting, and the `search_documents` projection.
- **`objects/`** — every uploaded file's actual bytes, when using embedded
  MinIO.
- **`minio.env`** — embedded MinIO's generated root credentials. Losing
  this without losing `objects/` alongside it does not lose data, but does
  mean the entrypoint script cannot reuse the existing bucket's credentials
  seamlessly; keep the two together.
- **`runtime.json`** — the encrypted database-connection override written by
  the first-run installer (spec §10a). Only present if you used the
  installer to point at PostgreSQL/MySQL rather than setting `DB_*` before
  first boot.
- **`.env`** — the self-generated `APP_KEY`, if you did not supply one
  yourself.

A plain volume backup (`docker run --rm -v doccum:/data -v $(pwd):/backup
alpine tar czf /backup/doccum-data.tar.gz -C /data .`, or your platform's
equivalent for the named volume) captures all of it in one pass, because it
is all in one place.

## `APP_KEY` travels with the backup, or must be supplied again

The `settings` table's secrets (storage credentials, anything set through
`Settings::setSecret()`) and `runtime.json` are both encrypted with
`APP_KEY`. If `/data/.env` is part of your backup (the default, when the key
was self-generated), this is automatic. If you set `APP_KEY` yourself as an
environment variable instead of letting it self-generate, you must supply
the *same* `APP_KEY` to whatever container reads the restored `/data` —
doccum checks this explicitly and reports a clear error rather than booting
half-working when the key does not match what encrypted the data (spec
§10a).

## If you moved off SQLite

With `DB_CONNECTION=pgsql` or `mysql`, the database itself is external to
`/data` and needs its own backup — `pg_dump`/`mysqldump` on your own
schedule, same as any other Postgres/MySQL database. `/data` still matters:
it holds `objects/` (unless you also moved storage off embedded MinIO),
`runtime.json` (which is how the container knows to reach that external
database at all), and `.env`.

## Restoring

Point a fresh container at a restored `/data` (and, if applicable, a
restored external database):

```bash
docker run -d --name doccum -v doccum:/data -p 8080:8080 ghcr.io/OWNER/doccum
```

Because everything except the *initial* database connection lives inside
the database itself, restoring `/data` (and the external database, if you
have one) restores the whole instance — users, roles, permissions, storage
settings, retention configuration, and every document. There is nothing
else to reinstall or reconfigure; the first request against a restored
`/data` finds existing users and goes straight to the login screen, not the
first-run installer. Bring the matching `APP_KEY` as described above, or
the app will tell you plainly that it cannot decrypt what it found rather
than silently starting empty.

## Verifying a backup actually works

`php artisan doccum:config:show` (see [Operations runbook](operations-runbook.md))
run against the restored container is the quickest way to confirm the
runtime configuration came back intact — secrets masked, but present. A
genuine restore drill — standing up a second container against a copy of
the backed-up volume and confirming login, a known file's download, and a
known search term all work — is worth doing at least once, before you need
it for real.
