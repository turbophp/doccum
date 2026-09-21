# Configuration reference

doccum reads well over a hundred environment variables, because it inherits
every one Laravel and its framework packages define. Almost all of them are
framework defaults a self-hosted install never touches — `APP_FAKER_LOCALE`,
`AUTH_GUARD`, the Beanstalkd and DynamoDB queue/cache drivers doccum never
wires up, and so on. Listing all of them here would bury the ones that
matter under the ones that don't, so this page documents only the variables
a self-hoster plausibly sets, each with its **local** value (what the
default stack gives you with nothing configured) next to its **remote**
value (what you set to move that concern outside the container).

The complete inventory — every variable read anywhere under `app/`,
`config/`, or `docker/`, and why each one absent from the tables below is
absent on purpose — is enforced by
`tests/Feature/SelfHostingDocsTest.php`'s `DOC_AUDIT_ENV_KEY_EXEMPTIONS`
registry: a variable that starts mattering to doccum and is not added to one
of the tables below fails that test, rather than silently going
undocumented.

## Application

| Variable | Local (default) | Remote / production |
|---|---|---|
| `APP_URL` | `http://localhost:8080` | Your instance's public URL, `https://` when behind TLS. Also the host `ForceRootUrlFromRequest` and generated links use. |
| `APP_KEY` | Self-generated on first boot by `docker/entrypoint.d/49-doccum-init.sh`, stored in `/data/.env` | Carry the same value between containers that share a database — it seals `runtime.json` (§10a) and every encrypted setting; a mismatch is reported explicitly rather than silently failing. |
| `APP_ENV` | `production` (set in `compose.yaml`) | Leave as `production`; there is no supported "staging" shape. |
| `APP_DEBUG` | `false` | Leave `false`. Turning it on in a reachable instance leaks stack traces and configuration. |
| `APP_NAME` | `doccum` (baked into the image) | The instance's *display* name is a separate `settings` row (`instance.name`, set in the installer or Settings) — this env var only affects the page `<title>` fallback and mail "from" name. |

## Database

| Variable | Local (default) | Remote / production |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | `pgsql` or `mysql` — choose in the first-run installer, or set here before first boot. |
| `DB_DATABASE` | `/data/doccum.sqlite` | The database name, once `DB_CONNECTION` is `pgsql`/`mysql`. |
| `DB_HOST` | unused (SQLite is a file, not a server) | Your PostgreSQL/MySQL host — `postgres` when using `compose.yaml`'s `db` profile, or a managed database's hostname. |
| `DB_PORT` | unused | `5432`/`3306` or your managed database's port. |
| `DB_USERNAME` | unused | Database user. |
| `DB_PASSWORD` | unused | Database password. |

These five apply only once you move off the SQLite default. **The
first-run installer's database step writes what you choose into
`/data/runtime.json` (encrypted with `APP_KEY`), and that file takes
precedence over these environment variables from then on** — see spec
§10a and `App\Providers\RuntimeConfigServiceProvider::applyDatabase()`.
Setting `DB_*` here still matters for the *very first* boot, before the
installer has run, and for the `compose db` profile's own `postgres`
service, which reads `DB_HOST`/`DB_PORT` indirectly through its own fixed
`POSTGRES_*` credentials in `compose.yaml`.

## Queue, cache and sessions

| Variable | Local (default) | Remote / production |
|---|---|---|
| `QUEUE_CONNECTION` | `database` | `redis`, once you opt into `compose.yaml`'s `cache` profile. |
| `CACHE_STORE` | `database` | `redis`, same profile. |
| `SESSION_DRIVER` | `database` | Usually left as `database` even with Redis in play — sessions are small and this avoids one more thing that must be reachable for login to work. |

Spec §13 calls this out explicitly: **no Redis container is required at
all**. The `cache` profile exists for when you want the speed, not because
the defaults are missing something.

## Mail

doccum sends real email: the first-run installer's verification link, the
email-verification bounce for an unverified account, and password resets. The
default mailer is `log`, which writes the message to `storage/logs` and
delivers nothing. **An instance left on the default has users who never
receive their verification mail and cannot reset a password** — so for any
instance with more than one person on it, this section is not optional.

Spec §13 names `MAIL_*` alongside `DB_*` and `AWS_*` as one of the boundaries
every deployment configures.

| Variable | Local (default) | Remote / production |
|---|---|---|
| `MAIL_MAILER` | `log` — nothing is sent; read the message in `storage/logs/laravel.log`. | `smtp` for a real server, or `ses`/`postmark`/`resend` if you would rather use an API transport. |
| `MAIL_HOST` | unset | Your SMTP host. |
| `MAIL_PORT` | `2525` | `587` for STARTTLS, `465` for implicit TLS — match what your provider documents. |
| `MAIL_USERNAME` | unset | The SMTP user. |
| `MAIL_PASSWORD` | unset | The SMTP password. Treat it like any other secret: pass it through the environment, not a committed file. |
| `MAIL_SCHEME` | unset (STARTTLS is negotiated) | Set `smtps` for implicit TLS on 465. Leave unset for 587. |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | The address mail arrives from. Several providers reject or spam-file mail whose From domain they do not sign, so set this to a domain you control. |
| `MAIL_FROM_NAME` | the instance name | What recipients see as the sender. |

To check it works end to end, trigger a password reset for your own account
and confirm the mail arrives — the reset link is also the quickest way to see
whether `APP_URL` is right, since the link is built from it.

## Object storage

| Variable | Local (default) | Remote / production |
|---|---|---|
| `FILESYSTEM_DISK` | `documents` | Unchanged — `documents` is doccum's one disk name; what it points *at* changes, not this. |
| `DOCCUM_DISK` | `documents` | Same disk name, read by `config/doccum.php` for the pieces of the app that go through `Services\Settings` rather than the filesystem manager directly. Keep it identical to `FILESYSTEM_DISK`. |
| `AWS_ACCESS_KEY_ID` | Generated per install into `/data/minio.env` by `docker/entrypoint.d/48-doccum-storage.sh` | Your S3/R2/Spaces/Wasabi/B2/MinIO access key. |
| `AWS_SECRET_ACCESS_KEY` | Same, generated | Your secret key. |
| `AWS_DEFAULT_REGION` | `us-east-1` (MinIO ignores region, but the SDK requires one) | The region your provider expects — R2 forces `auto`, Wasabi/B2 encode it into the endpoint instead. |
| `AWS_BUCKET` | `doccum` | Your bucket/container name. |
| `AWS_ENDPOINT` | `http://127.0.0.1:9000` inside the `app` container; `http://app:9000` from `worker`/`worker-ingest`/`scheduler` | Unset entirely for plain AWS S3, or your provider's endpoint URL. |
| `AWS_URL` | unset | Only needed if your provider serves public URLs from a different host than `AWS_ENDPOINT` — uncommon; leave unset otherwise. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` (MinIO requires it) | `false` for virtual-hosted providers (plain S3, R2, Spaces, Wasabi, B2) — see [Storage](storage.md) for which is which. |
| `DOCCUM_EMBEDDED_ENV` | `/data/minio.env` | Not normally changed; this is where embedded MinIO's generated credentials live, read by `RuntimeConfigServiceProvider::applyEmbeddedStorage()`. |

**Like the database block above, the installer's storage step writes your
choice into the `settings` table — encrypted, through `Settings::setSecret()`
— and that takes precedence over `AWS_*` at runtime.** These five/six
`AWS_*` variables mainly matter as the *embedded* default's own values (which
the entrypoint scripts and `RuntimeConfigServiceProvider` generate and apply
for you) and as the seed values a first boot starts from before you ever
open the installer. See [Storage](storage.md) for the full move-to-S3
walkthrough, including the provider presets that derive `AWS_ENDPOINT` and
addressing style for you.

## Redis (opt-in, `cache` profile)

| Variable | Local (default) | Remote / production |
|---|---|---|
| `REDIS_HOST` | `redis` (the compose service name, once the `cache` profile is active) | A managed Redis/Valkey host. |
| `REDIS_PORT` | `6379` | Your provider's port. |
| `REDIS_PASSWORD` | unset (the bundled `redis` service sets none) | Required for almost every managed Redis offering. |
| `REDIS_CLIENT` | `phpredis` (the PHP extension the base image installs) | Leave as `phpredis` unless you have a specific reason to switch to `predis`. |

## doccum-specific

| Variable | Local (default) | Remote / production |
|---|---|---|
| `DOCCUM_VERSION` | `0.1.0-dev` for a local build; the pushed tag for a released image (`Dockerfile`'s `ARG DOCCUM_VERSION`, mirrored into `LABEL org.opencontainers.image.version`) | Not something you set — it travels with the image. Read by `config('doccum.version')` for the topbar version pill. |
| `DOCCUM_RUNTIME_CONFIG` | `/data/runtime.json` | Only change this if `/data` itself is remapped; the file must stay on the persistent volume, never under `storage/` (that path lives in the image and is lost on rebuild). |

## Container-level toggles

These are not read by `env('...')` inside the PHP application at all — they
are read as shell variables by `docker/entrypoint.d/*.sh` and by
`docker/supervisor/doccum.conf`'s `%(ENV_...)s` interpolation, and by
`compose.yaml` itself. They belong in this reference anyway, because they
are exactly the knobs a self-hoster flips to go from "one container" to "one
container per role":

| Variable | Local (default) | Remote / production |
|---|---|---|
| `AUTORUN_ENABLED` | `true` on `app` only | `false` on `worker`/`worker-ingest`/`scheduler` — only one container may run migrations, or four containers race to migrate the same SQLite file. |
| `DOCCUM_EMBEDDED_STORAGE` | `true` on `app` only | `false` everywhere else — only one container may run MinIO against `/data/objects`. |
| `DOCCUM_RUN_WORKERS` | `true` on the single-container image | `false` when `compose.yaml`'s dedicated `worker`/`worker-ingest` containers already run them. |
| `DOCCUM_RUN_SCHEDULER` | `true` on the single-container image | `false` when a dedicated `scheduler` container already runs it. |
| `MINIO_ROOT_PASSWORD` | Not read by the default stack at all (embedded storage generates its own into `/data/minio.env`) | **Required**, with no default, for `compose.yaml`'s opt-in `storage` profile — a known password in a public compose file would be a known password in every install that copies it. Compose interpolates the whole file regardless of which profile you select, so this is required for *every* `docker compose` invocation, not only ones that use the profile — see the comment above `minio:` in `compose.yaml` and the README's "Going bigger" section. |

## Everything else

Every other environment variable this codebase can read — the four `MAIL_*`
stragglers the Mail section above does not cover (`MAIL_EHLO_DOMAIN`,
`MAIL_LOG_CHANNEL`, `MAIL_SENDMAIL_PATH`, `MAIL_URL`), every queue/cache/log
driver doccum doesn't ship a service for
(Beanstalkd, SQS, DynamoDB, Memcached), Fortify's passkey secret, and the
rest of stock Laravel's surface — is a framework default doccum's zero-
configuration boot never asks you to set. Each one is named, individually,
with the specific reason it is not a self-hosting concern, in
`tests/Feature/SelfHostingDocsTest.php`'s `DOC_AUDIT_ENV_KEY_EXEMPTIONS`
registry, which a test checks against the actual source so the list cannot
silently rot — either by missing a variable that starts to matter, or by
keeping an entry for one that no longer exists.
