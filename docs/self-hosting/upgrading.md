# Upgrading

## The steady-state case: pull a new tag

```bash
docker pull ghcr.io/turbophp/doccum:X.Y.Z
docker stop doccum && docker rm doccum
docker run -d --name doccum -v doccum:/data -p 8080:8080 ghcr.io/turbophp/doccum:X.Y.Z
```

Or, for the compose stack, update the image reference/tag in `compose.yaml`
(or your override) and run `docker compose up -d --build`.

Migrations run automatically. `AUTORUN_LARAVEL_MIGRATION` is on by default
for the single-container image, and `compose.yaml` scopes it to the `app`
service alone (`AUTORUN_ENABLED=true` there, `false` on `worker`,
`worker-ingest`, and `scheduler`) — so exactly one container ever runs
`php artisan migrate` against the shared SQLite file, never four racing
each other. Nothing else about the upgrade is different from a fresh boot:
the same entrypoint scripts run, `APP_KEY` and MinIO credentials are read
from `/data` rather than regenerated, and the application comes back up
against the same data it had before.

Check `CHANGELOG.md`'s `[Unreleased]`/latest version section before
upgrading past more than one release — doccum follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and anything that
needs a manual step on upgrade (there is nothing that does, as of this
writing) would be called out there under that version's own heading rather
than buried in a commit log.

## Confirming which version is actually running

The topbar's version pill reads `config('doccum.version')`, which comes
from the `DOCCUM_VERSION` environment variable baked into the image at
build time (`Dockerfile`'s `ARG DOCCUM_VERSION`, mirrored into the
`org.opencontainers.image.version` OCI label). `docker inspect
--format '{{ index .Config.Labels "org.opencontainers.image.version" }}'
doccum` confirms it independently of the running PHP process, which is
useful when diagnosing whether a `docker pull`/`docker run` actually picked
up the tag you expected.

## Image size

CI ratchets the image's compressed and uncompressed size on every build —
see the README's "Image size" section and `.github/scripts/image-size.sh` —
so an upgrade should never surprise you with a dramatically larger pull than
the previous release; if it does, that is worth reporting rather than
assuming is normal.

## Downgrading

Not a supported path, and the reason is data rather than tooling.

**Most migrations here do reverse.** 24 of the 25 in `database/migrations/`
have a `down()` with a body, several hand-written — the one that adds
`name_key` reverses its own index ordering on the way back out. So
`migrate:rollback` will usually *run*. That is not the same as being safe.

What makes it unsupported:

- **One migration cannot reverse at all.** The backfill that sets
  `email_verified_at` for accounts created before verification existed has an
  empty `down()`, because there is no record of which rows it touched.
- **A `down()` that works still destroys data.** Rolling back a column drop
  recreates the column empty; rolling back a table drop recreates it empty.
  The migration reverses; what was in it does not come back.

So a downgrade after a schema change has run against real data is a
restore-from-backup situation, not an image swap — see
[Backup and restore](backup-and-restore.md).

This section used to say that no migration has a working `down()` beyond
Laravel's default. That was false of 24 of them, and worth correcting rather
than deleting: a reader who checks a stated reason and finds it untrue has
learned something about the rest of the page too.

## Before a major version

Take the backup described in [Backup and restore](backup-and-restore.md)
first, every time, even though the steady-state path above has never
required one — the cost of a backup you didn't need is small, and the
project has no migration-rollback story to fall back on if an upgrade goes
wrong.
