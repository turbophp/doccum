# Storage

Every object doccum stores — a file version, a staged upload, a built
directory archive — goes through exactly one seam, `App\Services\DocumentStorage`,
which addresses whatever disk is configured as an S3 API (spec §6). That is
true whether the bytes actually land in embedded MinIO, a standalone MinIO,
or real S3: nothing above `DocumentStorage` knows or cares which.

## The default: embedded MinIO

Nothing to configure. On first boot, `docker/entrypoint.d/48-doccum-storage.sh`
generates a random MinIO root user/password into `/data/minio.env` (mode
`0600`), and `RuntimeConfigServiceProvider::applyEmbeddedStorage()` reads
that file at boot and points the `documents` disk at it. MinIO itself runs
inside the `app` container under `supervisord`
(`docker/supervisor/doccum.conf`'s `[program:minio]` block) — the `worker`,
`worker-ingest`, and `scheduler` containers reach it over the Docker network
at `http://app:9000` rather than each starting their own.

Because the credentials live on the same `/data` volume as everything else,
a restored backup carries working storage credentials with it — there is no
separate MinIO configuration to restore.

## Downloads and presigned URLs

Spec §6 requires that file bytes never stream through PHP: a download issues
a short-lived presigned URL and redirects. That only works when the
presigned URL names a host the *browser* can reach — and embedded storage's
default endpoint, `http://127.0.0.1:9000`, is not that host from a browser's
perspective; it is the browser's own machine. `DocumentStorage::servesPresignedUrls()`
detects exactly this (a loopback host in the configured endpoint) and falls
back to streaming through PHP instead of presigning, so downloads still
work — just without the "bytes never touch PHP" property — until you move
storage somewhere the browser can actually reach it. This is one of the
concrete reasons to move off the embedded default before exposing an
instance beyond your own machine; see [Troubleshooting](troubleshooting.md).

## Moving to S3, R2, or another remote MinIO

Two ways to change where objects live, both without a rebuild:

**Through the installer (recommended).** Settings → Storage lets you pick a
provider — Amazon S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze
B2, Azure Blob, or "custom" for any other S3-compatible endpoint — and fill
in the account/region/credentials it asks for. `App\Enums\StorageProvider`
derives the right endpoint template and addressing style for the presets
(`R2`, `Spaces`, `Wasabi`, `BackblazeB2` each compute their own endpoint from
the account or region you give; `Embedded` is the only preset that needs
path-style bucket addressing — every hosted provider uses virtual-hosted
addressing instead). The form probes the new location with a real `PUT` and
`DELETE` before it accepts the change, so a bad credential fails at the form
rather than at the next upload. What you choose here is written to the
`settings` table, encrypted through `Settings::setSecret()`, and takes
precedence over the `AWS_*` environment variables from then on — see spec
§10a.

**Through the environment**, before you ever open the installer: set
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
`AWS_BUCKET`, and `AWS_ENDPOINT` (unset `AWS_ENDPOINT` entirely for plain
AWS S3), plus `AWS_USE_PATH_STYLE_ENDPOINT=false` for every hosted provider
— only embedded/standalone MinIO needs path-style addressing. See the
[configuration reference](configuration-reference.md#object-storage) for
each variable's local and remote value side by side.

Either way, moving storage is an environment or settings change, never a
code change or a rebuild — that is the whole point of the seam.

## Running standalone MinIO instead of embedded

`compose.yaml` ships an opt-in `storage` profile with its own `minio` and
`minio-init` services, for anyone who wants object storage outside the
`app` container — for example, pointing several doccum installs at one
bucket. Bring it up with:

```bash
MINIO_ROOT_PASSWORD=<a real password> docker compose --profile storage up -d
```

`MINIO_ROOT_PASSWORD` has **no default on purpose** — a known password
committed to a public repository is a known password in every install that
copies it, so compose refuses to resolve the `storage` profile's services at
all until you supply one. Note that Compose interpolates the entire file
regardless of which profile you actually select, so this variable must be
set for *every* `docker compose` command against this project, whether or
not you use the `storage` profile — see the comment above `minio:` in
`compose.yaml`.

`minio-init` is a one-shot `mc` container that retries until MinIO answers
and then creates the `doccum` bucket — no `depends_on` ordering needed, and
none of the four application containers declare one either (spec §13): each
starts independently, and detaching storage is simply not starting the
embedded server, or pointing the app at this profile's `minio` service
instead via `AWS_ENDPOINT=http://minio:9000`.

That independence has one requirement, and it lives in the `Dockerfile` rather
than in `compose.yaml`: the image must ship **nothing** under `/data`. All four
containers mount the same `db:` volume there and are started at the same
instant, and Docker seeds a fresh named volume by copying whatever the image
holds at the mount path into it — so a single file shipped under `/data`
becomes a copy several daemons race to perform. One was: the base image ships
`/data/caddy`, and `docker compose up` on a fresh volume killed a worker with
`failed to mkdir …/doccum_db/_data/caddy: file exists`. The `Dockerfile` empties
`/data` for that reason, and CI refuses an image that reintroduces content
there.

Caddy still creates `/data/caddy` once the `app` container is running — it is
the only one of the four that serves HTTP — but that is one process calling
`MkdirAll`, which succeeds whether or not the directory is already there. The
failure was Docker's copy, not Caddy's directory. Nothing in it needs backing
up: with the default `SSL_MODE=off` doccum serves plain HTTP behind your
reverse proxy, so Caddy issues no certificates and the only file it writes
there is an instance id it regenerates when absent.

## What never changes

`use_path_style_endpoint` is forced `true` for embedded/standalone MinIO
because path-style is the only addressing MinIO's default configuration
accepts; every hosted provider preset in `App\Enums\StorageProvider` sets it
`false`. Object keys always carry the file's *creation* period
(`files/{YYYY}/{MM}/{file_uuid}/v{n}/{original-name}`, spec §6) regardless of
which provider stores them, which is what makes [archive and purge](operations-runbook.md)
a single coherent prefix operation on any provider.
