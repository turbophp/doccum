# Security policy

doccum is self-hosted software whose job is holding other people's documents.
A hole in it is not an inconvenience; it is somebody's filing cabinet standing
open. Reports are taken seriously and are welcome from anyone.

## Supported versions

doccum has not reached `v1.0.0`. Until it does, the supported version is
`main`, and there is no backporting: fixes land on `main` and reach operators
in the next image.

Once `v1.0.0` is tagged this section will name the supported release line.

## Reporting a vulnerability

**Use GitHub's private vulnerability reporting.** Go to the
[Security tab](https://github.com/turbophp/doccum/security) and choose *Report
a vulnerability*. That opens a private advisory visible only to the
maintainers — nothing is public until an advisory is published.

If that option is not visible to you, open a public issue that says **only**
that you have a security report and asks for a private channel. Do not put the
details in it. A vague issue that reaches a maintainer is better than a precise
one that reaches everybody.

Please do not report vulnerabilities in public issues, pull requests, or
discussions.

### What to include

- What an attacker can do, stated as an outcome — "a viewer with no grant on a
  directory can read its files" rather than "the policy looks wrong".
- How to reproduce it, ideally against a fresh `docker run` of the image.
- Which version or commit you tested.
- Whether you have published or shared it anywhere.

A failing test is the most useful possible report, and the fastest route to a
fix.

### What to expect

This is a small project, so an honest estimate rather than a generous one:

- An acknowledgement within **7 days**.
- An assessment — is it real, how bad, what is the fix — within **30 days**.
- Credit in the advisory and the release notes, unless you would rather not be
  named.

If you have not heard anything after 7 days, please chase; a missed
notification is far more likely than a decision to ignore you.

## Scope

**In scope** — the things this project is responsible for getting right:

- **Authorisation bypass across either layer.** doccum gates every action on
  two independent checks: a Spatie permission (may this person ever do this
  kind of thing) and `directory_access` (where, inherited down the subtree).
  Both must pass. Any path that reaches a document while failing either one is
  a vulnerability, including paths that work only for the API, only for the
  UI, or only for a specific storage backend.
- **Reach leaks through response differences.** A subject that exists but lies
  outside the viewer's reach must answer *identically* to an id that does not
  exist. A surface where the two differ — different status, different body,
  different timing — discloses the existence of documents the viewer may not
  see, and is in scope even though nothing was read.
- **Presigned URL problems** — a URL that outlives its window, that grants more
  than the operation it was issued for, or that can be derived rather than
  requested.
- **The first-run installer.** `/setup` creates the first administrator, and
  only while no user exists at all. Once one does, the route is **gone**:
  `App\Http\Middleware\RequireInstanceSetup` answers it with a 404, not a
  redirect to login, and `tests/Feature/FirstRunSetupTest.php`'s "closes setup
  once a user exists" asserts exactly that. Any path that gets the installer to
  create or elevate an account on an instance that already has users is
  critical — it is an unauthenticated route to administrator. So is any path
  that gets `/setup` to answer as anything but a 404 there, because the
  installer being merely *inert* rather than *absent* is a weaker guarantee
  than this one, and reporting a regression to it should not depend on knowing
  which of the two was intended.
- **Authentication** — session fixation, the password reset flow, the
  `auth.public_signup` setting being bypassable when off, two-factor
  enrolment.
- **Archive and purge** — anything that destroys documents without passing the
  guards, or that a token can reach. Admin operations are deliberately UI-only
  precisely so that no API token can reach `purge`; a token that reaches one is
  in scope.
- **Container escape or privilege escalation** from the shipped image, and
  anything that exposes `/data`.
- **Secrets in the image or in logs.**

**Out of scope:**

- Findings in `vendor/` dependencies. This repository never edits `vendor/`;
  report those upstream. If a dependency advisory needs doccum to *act* — a
  version bump, a configuration change — an ordinary public issue is the right
  place.
- Anything that requires an administrator account to exploit. Administrators
  can already purge every document; that is the role, not a bug.
- Missing hardening headers, TLS configuration, or rate limiting on a
  deployment. doccum runs behind whatever reverse proxy the operator chose, and
  those are the operator's to set. A *default* that actively undermines a
  correctly configured proxy is in scope.
- Denial of service through sheer volume against an instance you do not
  operate.
- Reports produced by running a scanner and pasting its output, with no
  demonstrated impact.

## Disclosure

Coordinated. We will agree a date with you, and by default publish the advisory
once a fixed image is available. If a fix is going to take longer than 90 days
we will say so and explain why rather than let the clock run out quietly.

## For operators

If you run doccum, two things matter more than anything in this file:

1. **Set `MINIO_ROOT_PASSWORD` before your first `docker compose up`.** The
   compose file refuses to start without it, deliberately.
2. **Keep `auth.public_signup` off** unless you mean it. It is off by default,
   and when it is off the registration route returns 404 rather than a disabled
   form, so the instance does not advertise an entry point it will not honour.

`docs/self-hosting/` covers the rest.
