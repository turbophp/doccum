#!/usr/bin/env python3
"""Assert the Dockerfile's Node major matches .nvmrc.

Issue #407. This repository states its Node version in three places and means
it: `.nvmrc` (22), `package.json`'s `engines.node` ("22.x"), and pages.yml,
which reads `.nvmrc` rather than keeping a fourth copy. CLAUDE.md gives the
reason a whole section -- npm 10 records `react`, motion's peer, in
package-lock.json, and npm 11 removes it AND rewrites the lockfile as a silent
side effect of `npm ci` and `npm run build` -- and says it has already cost
four CI cycles.

The Dockerfile's assets stage was on `node:26-bookworm-slim`. Nobody chose it:
Dependabot's docker group has pattern "*", and it walked the tag 24 -> 26 in
81d838d with no decision recorded anywhere. The image job stayed green, so this
was never a live break; the rewrite happens inside the build stage and cannot
reach the repository. What it meant is that the assets actually shipped in the
product were built by a toolchain this project's own rules call wrong for this
lockfile, and nothing would have said so.

WHY A CHECK RATHER THAN A COMMENT. dependabot.yml now ignores major updates for
this image, which stops the pull request being opened -- but a config that
stops matching (a renamed group, a changed pattern, an image moved to a
different directory) fails silently, and so does a hand edit. The two guards
fail at different times and neither subsumes the other: the ignore prevents,
this refuses. decision/0113: when something can be got wrong quietly, make a
tool REFUSE rather than describe and hope.

WHY THE MAJOR ONLY. `.nvmrc` carries a bare major (`22`); the image tag may
reasonably carry more (`22.11-bookworm-slim`, a digest pin). Comparing anything
finer would fail on a legitimate patch bump and teach the next person to
disable the check, which is worse than not having it. The major is the part
CLAUDE.md's npm argument actually turns on.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

DOCKERFILE = Path("Dockerfile")
NVMRC = Path(".nvmrc")

# `FROM node:<tag> AS <stage>` -- the tag may be `22`, `22.11`,
# `22-bookworm-slim`, or carry a digest after an `@`.
FROM_NODE = re.compile(
    r"^FROM\s+node:(?P<tag>[^\s@]+)(?:@[^\s]+)?\s+AS\s+(?P<stage>\S+)",
    re.MULTILINE,
)


def fail(message: str) -> None:
    print(f"::error::{message}")
    sys.exit(1)


def major_of(tag: str) -> str | None:
    match = re.match(r"^(\d+)", tag)
    return match.group(1) if match else None


def main() -> None:
    for path in (DOCKERFILE, NVMRC):
        if not path.exists():
            fail(f"{path} does not exist, so the Node-major check verified nothing.")

    expected = NVMRC.read_text().strip()
    expected_major = major_of(expected)
    if expected_major is None:
        fail(f".nvmrc reads {expected!r}, which does not start with a version number.")

    stages = FROM_NODE.findall(DOCKERFILE.read_text())
    if not stages:
        fail(
            f"{DOCKERFILE} has no `FROM node:<tag> AS <stage>` line. If the assets stage "
            "stopped using a node image, this check is now measuring nothing and should "
            "be removed deliberately rather than left passing."
        )

    for tag, stage in stages:
        found_major = major_of(tag)
        if found_major is None:
            fail(
                f"{DOCKERFILE} stage {stage!r} uses `node:{tag}`, whose major cannot be "
                f"read. Pin a numbered tag so it can be compared with .nvmrc ({expected})."
            )
        if found_major != expected_major:
            fail(
                f"{DOCKERFILE} stage {stage!r} builds on Node {found_major} "
                f"(`node:{tag}`) but .nvmrc says {expected}. package.json's engines and "
                "pages.yml agree with .nvmrc, so the image would ship assets built by a "
                "toolchain the rest of the repository forbids -- see CLAUDE.md's 'Node "
                "and the lockfile' section, which is about exactly this npm-major "
                "difference. Change both or neither."
            )

    listed = ", ".join(f"{stage} -> node:{tag}" for tag, stage in stages)
    print(
        f"Dockerfile and .nvmrc agree on Node {expected_major}: {listed} "
        f"(.nvmrc = {expected})."
    )


if __name__ == "__main__":
    main()
