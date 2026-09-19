# doccum

Self-hosted document management. Directories, versioned files, governed
metadata, and search that reaches inside the documents themselves — including
OCR of scanned pages.

## Run it

```bash
docker run -d --name doccum -v doccum:/data -p 8080:8080 ghcr.io/OWNER/doccum
```

Replace `OWNER` with the GitHub owner this image is published under. (Angle
brackets are deliberately not used here: `<owner>` in a shell is input
redirection, not a placeholder, and the command fails with a confusing
`No such file or directory`.)

Open <http://localhost:8080> and complete the setup screen.

### Building it yourself

Until the image is published, or to run your own build:

```bash
docker build -t doccum:local .
docker run -d --name doccum -v doccum:/data -p 8080:8080 doccum:local
```

That is the whole installation. One container runs the web application, both
queue workers, the scheduler and object storage; one volume holds everything
that must survive a restart. There is no configuration file to edit, no
database to create, no bucket to provision, and no default password — the first
account is created through the browser, once.

## Going bigger

The defaults are meant for the great majority of installs. When one machine
stops being enough, nothing needs rewriting:

- **A dedicated database.** Point the installer at PostgreSQL or MySQL. It
  probes the connection, migrates onto it, and carries on.
- **Dedicated object storage.** Choose a provider in the installer: Amazon S3,
  Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2, Azure Blob, or any
  S3-compatible endpoint. Embedded storage speaks S3 too, so this changes a
  setting rather than a code path.
- **More workers.** `compose.yaml` runs the queue workers and scheduler as
  separate containers. Use it when you want to scale them independently — not
  to get started. **Set `MINIO_ROOT_PASSWORD` before any `docker compose`
  command** — export it or put it in a `.env` file next to `compose.yaml`.
  There is no default, because a known password in a public repository is a
  known password in every install that copies it. The value only actually
  matters if you run the opt-in `storage` profile, which has no way to
  generate its own the way the embedded stack does; but compose interpolates
  the whole file whatever profile you select, so it is required either way.

Because everything except the database connection lives *in* the database,
pointing a fresh container at an existing doccum database restores the whole
instance: storage settings, users, roles and permissions. Bring `APP_KEY` with
it — encrypted settings and two-factor secrets are sealed with it, and doccum
will tell you plainly if it does not match rather than starting half-working.

## Image size

The image's size is measured on every build and, once a figure is on record,
CI fails a build that grows past it (`.github/scripts/image-size.sh`,
gated in `.github/workflows/tests.yml`'s `image` job and printed again by
`.github/workflows/release.yml`). Two numbers, each a ratchet on its own:

| | Uncompressed | Compressed |
|---|---|---|
| Size | 934.2 MiB (979,591,435 bytes) | 294.1 MiB (308,438,108 bytes) |
| Measured at commit | `70b4380` | `70b4380` |

Both figures come from the first CI run that built and measured the image,
not from an estimate. "Compressed" is a gzip of the whole `docker save`
tarball, not what a registry pull downloads — a pull fetches each layer's own
gzipped blob and skips layers it already has — so treat it as a stable way to
ask *did this get bigger*, not as a download time.

The check allows a small stated tolerance above the recorded figure, kept in
`.github/image-budget.json`. That is the measurement's noise floor rather than
slack: the Dockerfile pins its base images by tag rather than by digest, so two
builds of the same commit on different days differ by amounts this repository
did not cause.

## Recovering

| Situation | Command |
|---|---|
| See the effective runtime configuration, secrets masked | `php artisan doccum:config:show` |
| Discard runtime configuration and start over | `php artisan doccum:config:reset` |

Both work inside the container: `docker exec doccum php artisan ...`

## Licence

MIT.
