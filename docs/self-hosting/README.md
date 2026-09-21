# Self-hosting doccum

Documentation for running doccum yourself, as opposed to reading its API
(see `docs/api/openapi.json`, the committed OpenAPI 3.1 description of all
16 paths, which `tests/Feature/Api/OpenApiContractTest.php` holds to the
live route set in both directions) or its design (see
`docs/superpowers/specs/`).

1. [Quick start](quick-start.md) — `docker compose up`, the first-run setup
   screen, first upload.
2. [Configuration reference](configuration-reference.md) — every
   environment variable that matters, local default next to its remote
   value.
3. [Storage](storage.md) — MinIO by default; moving to S3 or a remote MinIO.
4. [Search](search.md) — the default database driver, and the upgrade path
   to a dedicated engine.
5. [Operations runbook](operations-runbook.md) — archive and purge, legal
   holds, the dry-run workflow.
6. [Backup and restore](backup-and-restore.md)
7. [Upgrading](upgrading.md)
8. [Troubleshooting](troubleshooting.md)
