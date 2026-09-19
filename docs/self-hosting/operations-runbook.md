# Operations runbook

Every command below runs inside the container: `docker exec doccum php
artisan ...` for the single-container image, or `docker compose exec app
php artisan ...` for the compose stack.

## The period lifecycle

A period (a calendar month or year, `archive_periods`) moves through three
states: **open** → **archived** (read-only; uploads targeting it are
rejected) → **purged** (rows and objects gone; the folder structure stays).
Nothing skips a state, and nothing is purged that was not first archived.

```
doccum:close-periods       # rolls up finished periods, marks them archived
doccum:purge-period Y [M]  # dry-run by default; --force actually deletes
doccum:purge-expired       # applies the configured retention window
```

## Archiving: `doccum:close-periods`

Scheduled automatically (see `docker/entrypoint.d/51-doccum-roles.sh`'s
neighbour, the `scheduler` container's `schedule:work`). Safe to run by hand
and safe to run twice — closing is idempotent, and it never reopens a
period that has already been purged. It counts every file and byte in each
finished period, writes the `archive_periods` row, and marks it archived.
Nothing is deleted at this step; archiving only stops new uploads from
landing in that period.

## The dry-run workflow: `doccum:purge-period`

```bash
docker exec doccum php artisan doccum:purge-period 2025          # whole year, dry run
docker exec doccum php artisan doccum:purge-period 2025 03       # one month, dry run
docker exec doccum php artisan doccum:purge-period 2025 03 --force  # actually delete
```

This is the one irreversible operation in doccum, so **dry-run is the
default, not an opt-in flag** — running the command with no `--force` always
reports what it would do (file count, byte count, and any blocker) and
deletes nothing. You have to ask twice, on purpose, to make it real.

A purge is refused, in dry-run or with `--force`, when either guard fails:

1. **Not archived, or not past the retention window.** Purge only ever
   touches a period that has already been marked archived by
   `doccum:close-periods` and that retention configuration says has aged
   out.
2. **A legal hold anywhere in the period.** Any file or directory with
   `legal_hold` set blocks its whole period from being purged — reported by
   name as a blocker rather than silently skipped, and the purge refuses
   the entire period rather than deleting everything except the held file.

When a purge does run, it cascades `file_versions`, `file_texts`,
`properties`, and `search_documents` for everything in that period, and
removes the MinIO/S3 prefix under `files/{YYYY}/{MM}/`. Directories survive
— a purged year leaves its folder structure standing and empty, so the tree
still shows where records used to live.

## Legal holds

Set from a file's detail panel (requires the `periods.manage` permission and
directory access to the file — both authorisation layers, per CLAUDE.md's
"Authorisation" section) or via `App\Actions\Files\SetLegalHold`. A held
file is not read-only for editing or downloading — only for purge. Lifting
a hold does not retroactively purge anything on its own; the next scheduled
or manual `doccum:purge-period`/`doccum:purge-expired` run picks it up like
any other now-purgeable file.

## Automatic purging is off by default: `doccum:purge-expired`

Even with a retention window configured (Settings → Instance, or
`retention.purge_after_years` in the `settings` table), nothing is deleted
on a schedule unless `retention.auto_purge` is also turned on. With it off,
`doccum:purge-expired` still runs on the schedule and *reports* every
period that has aged past the window as a candidate — visible in the
scheduler container's logs — without deleting anything. This two-step
default exists because nobody is watching when the scheduler fires, and an
instance that was never deliberately configured for retention must not be
the one that starts deleting records unattended.

## Trash

Soft-deleted directories and files go through the same purge machinery as
archived periods — a Trash page restores or permanently purges one item at
a time, and the retention/legal-hold guards above apply identically there.

## Checking and resetting runtime configuration

| Situation | Command |
|---|---|
| See the effective runtime configuration, secrets masked | `php artisan doccum:config:show` |
| Discard runtime configuration (database/storage settings from the installer) and start the first-run flow over | `php artisan doccum:config:reset` |
| Ensure Spatie roles/permissions exist without deleting any an operator edited | `php artisan doccum:ensure-roles` |
| Retry text extraction for files whose extraction failed | `php artisan doccum:extract:retry` |
| Reset a locked-out user's password from the command line | `php artisan doccum:user:reset-password` |
