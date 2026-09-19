# Troubleshooting

## "The runtime configuration ... could not be read"

Full message: *"The runtime configuration at /data/runtime.json exists but
could not be read. This usually means APP_KEY has changed since it was
written. Restore the original APP_KEY, or remove the file to reconfigure
from scratch."* — `App\Exceptions\RuntimeConfigUnreadable`.

This means `/data/runtime.json` (the encrypted database connection the
first-run installer wrote, spec §10a) cannot be decrypted with the
`APP_KEY` this container was started with. doccum deliberately does **not**
fall back to environment defaults here — an empty-looking database would
invite reinstalling over live data. Two ways out:

- **You have the original `APP_KEY`:** supply it (check whether it was
  self-generated into `/data/.env` and that file is intact) and restart.
- **You don't, and you're certain there is nothing to lose:**
  `docker exec doccum php artisan doccum:config:reset` deletes the file
  (even though it cannot be read) and the first-run installer runs again
  on the next request.

## "Set MINIO_ROOT_PASSWORD to the password this MinIO should use"

Any `docker compose` command against this project fails with this refusal
unless `MINIO_ROOT_PASSWORD` is set in the environment or a `.env` file next
to `compose.yaml` — even if you never intend to use the `storage` profile.
This is because Compose interpolates the entire file regardless of which
profile is selected, and the `storage` profile's own `minio` service has no
default password on purpose (a known password in a public repository is a
known password everywhere). The default stack (no `storage` profile) never
actually *uses* this value — embedded storage generates its own random
credentials into `/data/minio.env` — but Compose still needs it to resolve.
Set any value: `export MINIO_ROOT_PASSWORD=$(openssl rand -hex 24)` before
running `docker compose up`.

## A download 500s, or a presigned link is unreachable

If storage is still on the embedded default (`http://127.0.0.1:9000`) and
the instance is reached from a browser on a *different* machine than the
one running the container, presigned URLs name a host — `127.0.0.1` — that
means "this browser's own machine" to the browser, not "the doccum
container". `DocumentStorage::servesPresignedUrls()` detects a loopback
endpoint and falls back to streaming the download through PHP instead, so
this specific case should not 500 — but it does mean downloads are slower
and file bytes pass through PHP, which spec §6 tries to avoid. Move storage
to an endpoint the browser can actually resolve (a real hostname, or at
least the Docker host's LAN address) — see [Storage](storage.md).

## "Object [...] was not found in storage"

`App\Exceptions\ObjectMissingFromStorage`. The database row (`file_versions`)
exists, but the object store has no bytes for the key it points at. In
practice this means `/data` was restored without `objects/` alongside it —
the single most likely operator mistake, per CLAUDE.md's own framing of
"`/data` is the only persistent volume": restoring the SQLite database (or
pointing at a restored external database) while the object storage volume
was not restored the same way, or was restored from an *older* backup than
the database. There is no automatic recovery from this — the bytes are
genuinely gone if they were not backed up — but the error is explicit
rather than a generic 500, so you know immediately which file and version
is affected rather than discovering it from a support ticket. See
[Backup and restore](backup-and-restore.md) for keeping the database and
`objects/` backed up together.

## The first-run setup screen won't come back / signup is a 404

Both are deliberate. The first-run route (spec §10) becomes permanently
unreachable the moment any user exists — there is no way to re-run it short
of `doccum:config:reset` (which resets *database configuration*, not
users) or deleting every row in the `users` table yourself. Similarly,
`auth.public_signup` is off by default, and when it's off the registration
route returns a plain 404 rather than a disabled form — so a scan of the
instance does not reveal an entry point that exists but refuses everything
sent to it. Turn signup on from Settings if you want it; there is no
environment variable for this, only the `settings` row.

## Search returns nothing, or extraction never finishes

- Check the `worker-ingest` container's logs specifically — `ExtractText`
  runs on the dedicated `ingest` queue precisely so a slow OCR job on one
  file cannot block everything else, but it also means ingest problems show
  up there and nowhere else.
- A scanned PDF or image genuinely needs `tesseract-ocr`, which the base
  image installs; a `.doc`/`.xls` (not `.docx`/`.xlsx`) needs LibreOffice,
  which is **not** in the image unless it was built with
  `--build-arg WITH_OFFICE=true` — the default build omits it because the
  pure-PHP extractor already covers modern Office formats and LibreOffice
  is a meaningful image-size cost for a format most installs never see.
- `doccum:extract:retry` retries every file whose extraction previously
  failed, without needing to re-upload.
- If extraction succeeded but a search still finds nothing, remember that
  search only ever returns what the signed-in user can otherwise see —
  `Services\Search::for()` filters inside the query by the viewer's
  reachable directories. A result "missing" for one user and present for an
  admin is very likely `directory_access`, not a broken index; see
  [Search](search.md) and CLAUDE.md's "Authorisation" section.

## Nothing boots at all / healthcheck never turns green

`AUTORUN_ENABLED` (and its migration/storage-link sub-flags) is what runs
migrations at boot — check that exactly one container in your stack has it
`true` (the `app` service, in `compose.yaml`) and that the others are
`false`; four containers racing to migrate the same SQLite file is a real
failure mode, not a hypothetical one, which is why `compose.yaml` is
careful about it (see the [configuration reference](configuration-reference.md#container-level-toggles)).
Beyond that, `docker logs doccum` (or the specific compose service) during
boot shows each `entrypoint.d` script's own output — they print what they
generated (an `APP_KEY`, MinIO credentials) or why they skipped, in plain
text, before the application itself starts.
