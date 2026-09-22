# Changelog

All notable changes to doccum are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project intends to adopt [Semantic Versioning](https://semver.org/)
from 1.0.0 onward.

`.github/scripts/changelog-section.sh` extracts a single version's section
from this file verbatim as the body of that version's GitHub Release, so keep
each entry to what a user of the product would care about, and keep the
heading format to `## [x.y.z]` or `## [x.y.z] - YYYY-MM-DD`.

An **undated** `## [x.y.z]` heading means that version has not been released
yet: under Keep a Changelog a date means shipped, so the date is added when
the tag is cut. That explanation belongs HERE and never inside a version's
section. A version's section is published verbatim as its GitHub Release, so
a sentence in it saying no release exists would be the first thing a reader
of that very Release saw.

## [Unreleased]

## [0.1.0]

Initial feature set.

### Added

- Directory tree with a three-pane files view (tree sidebar, list and detail
  panel) and breadcrumb navigation.
- File version history and replace-in-place, with each version individually
  downloadable.
- Dense file list with column sorting, multi-select and bulk trash.
- Rename and move actions for directories and files.
- Legal hold, to stop a file being purged by a retention period.
- Trash: trashing a directory hides everything beneath it; a Trash page
  restores items or purges them permanently, one at a time.
- Home dashboard: recent files, storage by period, and quick search.
- Search filters by type, MIME type, period and custom properties.
- Directory access can be granted and revoked from the UI.
- Settings: users, roles and their permission matrix, retention periods
  (closing and purge plans), and instance-wide settings (name, signup,
  retention limits).
- Login accepts a username as well as an email address.
- `user:reset-password` console command, so a locked-out admin can regain
  access without database access.
- Runs correctly behind a TLS-terminating reverse proxy, serving correct
  URLs (respects `X-Forwarded-Proto`/`X-Forwarded-Host`).
- Preview a file in the browser without downloading it.
- Command palette: press Ctrl-K (Cmd-K on a Mac) anywhere to search and jump
  straight to a result.
- Search matches partial words, so a query finds a term it only begins.
- Download a whole directory as a zip. The archive is built in the
  background and a notification appears when it is ready, so a large
  directory does not tie up the page.
- REST API under `/api/v1`: browse directories, read and upload files
  (presigned upload plus commit), list versions, read extracted text, and
  set properties. A generated OpenAPI reference is committed at
  `docs/api/openapi.json`.
- Settings: personal API tokens, each limited to a fixed set of eight
  coarse abilities.
- Self-hosting documentation covering install, configuration and upgrade.

### Fixed

- Downloads reliably reach the browser instead of failing silently.
- A missing storage object now returns a clear error instead of a crash, on
  both the download and text-extraction paths.
- Uploads that were silently discarded now surface as errors the operator
  can see and retry.
- A role's permissions edited in Settings now survive a container restart.
- Upgrading an existing instance no longer locks out every user.
- Email verification now consistently enforces what the instance settings
  say it does.

### Security

- Removed the hard-coded default MinIO password from `compose.yaml`; the
  storage profile now requires `MINIO_ROOT_PASSWORD` to be set explicitly.
- A document you cannot reach is no longer distinguishable from one that does
  not exist. Across the files view and the preview, download, version-download
  and directory-archive routes, an id outside your reach and an id that exists
  nowhere now answer identically, so working through ids no longer discloses
  what an instance holds. The `/api/v1` surface already behaved this way.
