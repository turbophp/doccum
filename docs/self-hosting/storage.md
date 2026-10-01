# Storage

Every object doccum stores — a file version, a staged upload, a built
directory archive — goes through exactly one seam, `App\Services\DocumentStorage`,
which addresses whatever disk is configured as an S3 API (spec §6). That is
true whether the bytes actually land in the embedded versitygw, a standalone
versitygw, or real S3: nothing above `DocumentStorage` knows or cares which.

## The default: embedded versitygw

Nothing to configure. On first boot, `docker/entrypoint.d/48-doccum-storage.sh`
generates the embedded store's root credentials — access key `doccum`, a
random secret — into `/data/storage.env` (mode `0600`) as `ROOT_ACCESS_KEY`
and `ROOT_SECRET_KEY`, and `RuntimeConfigServiceProvider::applyEmbeddedStorage()`
reads that file at boot and points the `documents` disk at it. The store
itself, [versitygw](https://github.com/versity/versitygw), runs inside the
`app` container under `supervisord` (`docker/supervisor/doccum.conf`'s
`[program:storage]` block, launched through `/usr/local/bin/doccum-storage`)
— the `worker`, `worker-ingest`, and `scheduler` containers reach it over the
Docker network at `http://app:9000` rather than each starting their own.
Inside the `app` container it answers at `http://127.0.0.1:9000`, path-style,
region `us-east-1`, bucket `doccum`.

versitygw runs with its `posix` backend against `/data/objects`, so every
object is an ordinary file on disk rather than an entry in an opaque store.
You can look at the tree with normal tools, and a backup of `/data` is a
backup of the documents themselves — see [Backup and restore](backup-and-restore.md).
versitygw also keeps its own account store in `/data/storage-iam`; doccum only
ever uses the root account, but the directory exists and belongs in a backup
alongside the rest of `/data`.

Because the credentials live on the same `/data` volume as everything else,
a restored backup carries working storage credentials with it — there is no
separate object-store configuration to restore.

A volume created by an older doccum, back when the embedded store was MinIO,
cannot be read by versitygw; the container refuses to boot on it rather than
start empty. See [Upgrading](upgrading.md#a-volume-created-with-embedded-minio).

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

## Moving to S3, R2, or another remote S3-compatible store

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
— only the embedded/standalone store needs path-style addressing. See the
[configuration reference](configuration-reference.md#object-storage) for
each variable's local and remote value side by side.

Either way, moving storage is an environment or settings change, never a
code change or a rebuild — that is the whole point of the seam.

## Running a standalone store instead of embedded

`compose.yaml` ships an opt-in `storage` profile with its own `storage`
service — the same versitygw the image embeds, from `ghcr.io/versity/versitygw`
pinned by digest — for anyone who wants object storage outside the
`app` container — for example, pointing several doccum installs at one
bucket. Its data lives in the `objects` volume, with versitygw's account
store in `objects-iam`. Bring it up with:

```bash
MINIO_ROOT_PASSWORD=<a real password> docker compose --profile storage up -d
```

`MINIO_ROOT_PASSWORD` has **no default on purpose** — a known password
committed to a public repository is a known password in every install that
copies it, so compose refuses to resolve the `storage` profile's services at
all until you supply one. Note that Compose interpolates the entire file
regardless of which profile you actually select, so this variable must be
set for *every* `docker compose` command against this project, whether or
not you use the `storage` profile — see the comment above `storage:` in
`compose.yaml`. (The variable keeps its MinIO-era name for compatibility; it
will be renamed separately.)

There is no bucket-init service: the app creates the `doccum` bucket itself
through the AWS SDK it already carries (`DocumentStorage::ensureBucket()`) —
no `depends_on` ordering needed, and none of the four application containers
declare one either (spec §13): each starts independently, and detaching
storage is simply not starting the embedded server, or pointing the app at
this profile's `storage` service instead via `AWS_ENDPOINT=http://storage:9000`.

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

`use_path_style_endpoint` is forced `true` for embedded/standalone storage
because the embedded server is reached at `127.0.0.1`, which requires
path-style addressing (`StorageProvider::usesPathStyle()`); every hosted provider preset in `App\Enums\StorageProvider` sets it
`false`. Object keys always carry the file's *creation* period
(`files/{YYYY}/{MM}/{file_uuid}/v{n}/{original-name}`, spec §6) regardless of
which provider stores them, which is what makes [archive and purge](operations-runbook.md)
a single coherent prefix operation on any provider.
