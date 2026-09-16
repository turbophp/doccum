# doccum

Self-hosted document management. Directories, versioned files, governed
metadata, and search that reaches inside the documents themselves — including
OCR of scanned pages.

## Run it

```bash
docker run -d --name doccum -v doccum:/data -p 8080:8080 ghcr.io/<org>/doccum
```

Open <http://localhost:8080> and complete the setup screen.

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
  to get started.

Because everything except the database connection lives *in* the database,
pointing a fresh container at an existing doccum database restores the whole
instance: storage settings, users, roles and permissions. Bring `APP_KEY` with
it — encrypted settings and two-factor secrets are sealed with it, and doccum
will tell you plainly if it does not match rather than starting half-working.

## Recovering

| Situation | Command |
|---|---|
| See the effective runtime configuration, secrets masked | `php artisan doccum:config:show` |
| Discard runtime configuration and start over | `php artisan doccum:config:reset` |

Both work inside the container: `docker exec doccum php artisan ...`

## Licence

MIT.
