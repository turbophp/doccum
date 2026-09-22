#!/usr/bin/env python3
"""Assert that release.yml's `verify-anonymous-pull` job really is anonymous.

Issue #406. GitHub publishes a new package PRIVATE -- its documentation is
explicit that a package linked to a repository "automatically inherits the
access permissions (but not the visibility) of the linked repository" -- and
every other job in release.yml that pulls the published image logs in first.
Each of those is individually right; together they mean nothing in this
repository ever makes the assumption a self-hoster makes. Without that, the
v1.0.0 tag goes green end to end while `docker run ghcr.io/turbophp/doccum`
out of README.md answers `denied` for everyone.

WHY A STATIC ASSERTION RATHER THAN A MUTATION OF THE JOB ITSELF, which is the
same reason assert-published-smoke.py gives for its sibling and is worth
repeating rather than cross-referencing: release.yml fires on a tag push or a
workflow_dispatch, this repository has no tag, and the job is guarded to
`startsWith(github.ref, 'refs/tags/')`. No run reachable from here executes
it, so there is nothing to observe pass or fail. A mutant of an unrunnable
job is worse than no mutant, because it looks like evidence.

WHAT IS MUTATION-PROVABLE IS THIS SCRIPT, and it is also the thing that will
actually rot. The failure mode is not the pull command changing; it is
somebody adding a `docker/login-action` step to this job -- reasonably, while
fixing something else, exactly as the four sibling jobs each reasonably have
one. That edit would leave a green check asserting nothing, which is the
shape decision/0113 is about: make the tool REFUSE rather than describe.

WHY `docker logout` IS REQUIRED AND NOT MERELY "no login step". Absence of a
login step asserts nothing about ambient credentials: a runner image, a
leftover config from another job, or a workflow-level login added later would
all authenticate the pull silently. Anonymity has to be an ACT. So this
script requires the logout to be present AND to come before the first pull --
a logout after the pull would be theatre, and a check that accepted it would
be theatre about theatre.

WHY yaml.safe_load RATHER THAN grep, the same reason assert-release-gate.py
and assert-published-smoke.py give: reflowing a `run:` block, reordering
steps or moving keys are all the same graph and must all still pass, while a
step that actually authenticates must fail however the YAML is laid out.
"""

from __future__ import annotations

import sys
from pathlib import Path

import yaml

WORKFLOW = Path(".github/workflows/release.yml")
JOB = "verify-anonymous-pull"

# Anything that hands the Docker CLI a credential. `docker login` covers the
# shell form; the action covers the declarative one. Matched as substrings
# because both carry a version suffix (a pinned SHA, in this repository).
CREDENTIAL_MARKERS = ("docker/login-action", "docker login")


def fail(message: str) -> None:
    print(f"::error::{message}")
    sys.exit(1)


def main() -> None:
    if not WORKFLOW.exists():
        fail(f"{WORKFLOW} does not exist, so the anonymous-pull guard checked nothing.")

    workflow = yaml.safe_load(WORKFLOW.read_text())
    jobs = workflow.get("jobs") or {}

    job = jobs.get(JOB)
    if job is None:
        fail(
            f"{WORKFLOW} has no `{JOB}` job. Issue #406: without it, nothing in this "
            "repository ever pulls the published image the way a self-hoster does, so a "
            "private ghcr package passes every check and the README's quick start fails "
            "for everyone."
        )

    steps = job.get("steps") or []
    if not steps:
        fail(f"`{JOB}` has no steps, so it asserts nothing.")

    # 1. No credential may be handed to Docker anywhere in this job.
    for index, step in enumerate(steps):
        blob = f"{step.get('uses', '')}\n{step.get('run', '')}"
        for marker in CREDENTIAL_MARKERS:
            if marker in blob:
                fail(
                    f"`{JOB}` step {index} ({step.get('name', 'unnamed')!r}) contains "
                    f"{marker!r}. This job's entire purpose is to pull WITHOUT "
                    "credentials -- with a login it would pass against a private "
                    "package, which is precisely the failure it exists to catch. If the "
                    "pull needs authentication, it belongs in one of the four sibling "
                    "jobs that already have it, not here."
                )

    # 2. The job must not request the packages scope. A credential it cannot
    #    hold is a credential it cannot accidentally use.
    permissions = job.get("permissions")
    if permissions is None:
        fail(
            f"`{JOB}` declares no `permissions:`, so it inherits the workflow default "
            "and may hold a packages token. Declare `permissions: {}`."
        )
    if isinstance(permissions, dict) and "packages" in permissions:
        fail(
            f"`{JOB}` requests `packages: {permissions['packages']}`. It must hold no "
            "package scope at all -- declare `permissions: {}`."
        )

    # 3. A logout must happen, and must precede the first pull.
    logout_at = next(
        (i for i, s in enumerate(steps) if "docker logout" in (s.get("run") or "")),
        None,
    )
    pull_at = next(
        (i for i, s in enumerate(steps) if "docker pull" in (s.get("run") or "")),
        None,
    )

    if pull_at is None:
        fail(f"`{JOB}` never runs `docker pull`, so it proves nothing about visibility.")

    if logout_at is None:
        fail(
            f"`{JOB}` never runs `docker logout`. Omitting a login step is not enough: "
            "ambient credentials from the runner image, another job, or a "
            "workflow-level login would authenticate the pull silently and this check "
            "would pass on a private package. Anonymity has to be an act."
        )

    if logout_at > pull_at:
        fail(
            f"`{JOB}` runs `docker logout` at step {logout_at} but pulls at step "
            f"{pull_at}. A logout after the pull asserts nothing about the pull."
        )

    # 4. The error the job PRINTS must name the remedy. A red check whose
    #    message does not say "make the package public" sends the reader
    #    looking for a build failure.
    #
    #    SCANNED OVER `::error::` LINES ONLY, NOT THE WHOLE `run:` BODY, and
    #    that distinction was found by mutation rather than reasoned out. The
    #    first draft searched the body, and a mutant that stripped "visibility"
    #    from both error messages still passed -- because the word survived in
    #    a SHELL COMMENT a few lines up ("Visibility is a property of the
    #    PACKAGE, not of a tag within it"). The check was being satisfied by
    #    prose explaining the job to a reader, not by the message a failing run
    #    actually prints, which is the only text the person debugging it sees.
    errors = [
        line.strip()
        for step in steps
        for line in (step.get("run") or "").split("\n")
        if "::error::" in line
    ]

    if not errors:
        fail(
            f"`{JOB}` prints no `::error::` line, so a failure would surface as a bare "
            "non-zero exit with nothing said about why."
        )

    if not any("visibility" in line.lower() for line in errors):
        fail(
            f"`{JOB}`'s `::error::` output never mentions visibility. When this job goes "
            "red the cause is a repository setting nobody has flipped, not a broken "
            "build, and that message is the only place the reader is told so."
        )

    print(
        f"{WORKFLOW}: `{JOB}` pulls anonymously -- no credential in {len(steps)} step(s), "
        f"no packages scope, `docker logout` at step {logout_at} before the pull at step "
        f"{pull_at}, and the failure message names the package-visibility remedy."
    )


if __name__ == "__main__":
    main()
