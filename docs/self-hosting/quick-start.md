# Quick start

Two ways to run doccum, both producing the same complete instance: one
container with `docker run`, or the fuller `compose.yaml` stack with
dedicated worker containers. Neither needs a config file edited or an
account created before it starts.

## The single container

```bash
docker run -d --name doccum -v doccum:/data -p 8080:8080 ghcr.io/OWNER/doccum
```

Replace `OWNER` with the GitHub owner the image is published under, or build
your own from a checkout with `docker build -t doccum:local .` and run that
tag instead. This one container runs the web application, both queue
workers, the scheduler, and embedded MinIO — see `docker/supervisor/doccum.conf`.
The `doccum` volume, mounted at `/data`, is the only thing that has to
survive a restart: SQLite, uploaded objects, `runtime.json`, and MinIO's
generated credentials all live there.

## The compose stack

```bash
docker compose up -d --build
```

`compose.yaml` runs `app`, `worker`, `worker-ingest`, and `scheduler` as four
containers sharing the same image and the same `/data` volume, so a spike in
OCR jobs cannot starve a page load. This is the shape to reach for once you
want to scale a role independently — not a requirement to get started, and
not a different product: the single container above and this stack boot the
same application the same way.

The default profile needs nothing else: no database container, no Redis, no
external MinIO. `COMPOSE_PROFILES` opts additional services in — see
[Storage](storage.md) for the `storage` profile and the
[configuration reference](configuration-reference.md) for `db` and `cache`.

## First boot

Whichever way you started it, the first request to `http://localhost:8080`
finds zero users in the database and redirects to a one-time **first-run
setup screen** instead of a login form. That screen is a three-step
installer, in this order:

1. **Database** — keep the SQLite default, or point at PostgreSQL/MySQL. The
   form probes the connection for real before it lets you continue, so a
   typo in a hostname fails here rather than at first use.
2. **Storage** — keep embedded MinIO (the default; nothing to fill in), or
   choose S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2, Azure
   Blob, or a custom S3-compatible endpoint. Same probing: a real `PUT` and
   `DELETE` against the bucket before the step accepts your answer.
3. **Admin account** — username, email, password, and the instance's display
   name. This is the only account this route will ever create; the moment it
   exists, the first-run route stops resolving. There is no default
   password at any point in doccum's lifecycle.

Once the admin account exists, sign in and you land on **Files**. There is
no separate landing page to configure.

## First upload

From Files, either drag a file onto the centre pane or use the upload
button. The upload goes through `StoreFileVersion`, is written to whichever
storage you chose in step 2, and a `file_versions` row and a queued
`ExtractText` job appear immediately. Text extraction and search indexing
happen on the `ingest` queue in the background — the file is visible,
downloadable, and versioned right away; searching for words inside it
becomes possible a few seconds later, once extraction finishes. If nothing
seems to happen, `docker logs doccum` (or `docker compose logs worker-ingest`
for the compose stack) is the first place to look, and
[Troubleshooting](troubleshooting.md) covers the specific failure modes that
have come up in practice.

## Where to go next

- [Configuration reference](configuration-reference.md) — every environment
  variable that actually matters, local default next to its remote/production
  value.
- [Storage](storage.md) — moving off embedded MinIO.
- [Search](search.md) — what ships today and the upgrade path to a dedicated
  engine.
- [Operations runbook](operations-runbook.md) — archive, purge, and legal
  holds.
