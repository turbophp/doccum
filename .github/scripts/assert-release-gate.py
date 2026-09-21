#!/usr/bin/env python3
"""Assert release.yml's `needs:` edges, and that no dependency can skip out from under one.

Issue #303. `release` used to be `needs: image`, and `boot-published-image`
was ALSO `needs: image` -- so on a tag push the GitHub Release, which is the
user-visible announcement, was created in PARALLEL with the job proving the
published image starts. A red arm64 leg arrived after the announcement was
already public.

WHY A STATIC ASSERTION RATHER THAN A MUTATION. release.yml fires only on a
tag push or a workflow_dispatch. This project has no tag, and a dispatch on
main would publish ghcr `latest` with DOCCUM_VERSION=latest -- the
unversioned publish decision/0090 warned against -- so no run available here
exercises the dependency. Left as a comment, "release waits for the boot"
would be a claim nothing checks. decision/0113: when a guard cannot be
exercised, make something REFUSE rather than describe and hope.

WHY yaml.safe_load RATHER THAN grep. `needs: [image, boot-published-image]`,
a block list over three lines, and a reordered list are the same graph and
must all pass; a dependency that is gone must fail however it is spelled. A
grep for a literal string answers a question about formatting instead.
"""

from __future__ import annotations

import sys

import yaml

WORKFLOW = '.github/workflows/release.yml'
# Each entry is one `needs:` edge this workflow must not lose, with the
# sentence explaining what goes wrong if it does. A LIST rather than the
# single pair this started as (issue #358): the first edge keeps the Release
# from being announced before the image is known to boot, the second keeps
# anything from reaching the registry before the tag's changelog section is
# known to exist. Both are edges nobody can test here, because release.yml
# runs only on a tag push or a dispatch.
REQUIRED_EDGES = [
    (
        'release',
        'boot-published-image',
        'The GitHub Release would be published in parallel with the job proving '
        'the image boots, so a failing arm64 leg would arrive after the '
        'announcement was already public. See issue #303, and item/release-v0-1-0, '
        'which says to drop linux/arm64 rather than ship a manifest nobody has '
        'booted.',
    ),
    (
        'image',
        'changelog-section',
        'A tag whose CHANGELOG.md section is missing would push the image and '
        'latest to ghcr and only then fail at `release`, leaving an image '
        'published with no Release -- the half-published state '
        'item/changelog-release-notes exists to prevent, on the one tag that '
        'cannot be retried. See issue #358.',
    ),
    (
        'release',
        'verify-version-label',
        'The Release would be announced in parallel with the job proving the '
        'published image\'s org.opencontainers.image.version label equals the '
        'tag that triggered the run, so a self-hoster could be told v1.0.0 '
        'exists while `docker inspect` on it says something else. Exactly the '
        'issue #303 argument, applied to the second of the three post-image '
        'verifications rather than the first.',
    ),
    (
        'release',
        'verify-latest-resolves-to-the-tag',
        'The Release would be announced in parallel with the job proving a '
        'bare `docker pull ghcr.io/turbophp/doccum` -- the exact command the '
        'README quick start gives -- resolves to the manifest this tag '
        'published. Announcing a version the quick start does not hand people '
        'is the issue #303 failure in its most user-visible form.',
    ),
]

# A dependency that can skip when its dependent would run is not a gate: a
# SKIPPED `needs` propagates its skip to the dependent, so narrowing a
# dependency's `if:` silently turns `release` off rather than making it wait.
# Measured on this repository rather than recalled --
# https://github.com/turbophp/doccum/actions/runs/35648660548 ran a job with
# `if: false` and a dependent whose own `if:` named something else; the
# dependent was SKIPPED, and only a dependent written `!failure() &&
# !cancelled()` ran.
#
# So every edge above must also satisfy: the dependency's `if:` is ABSENT
# (it always runs, and can never skip the dependent) or STRING-IDENTICAL to
# the gated job's (it runs exactly when the gated job would). Anything else
# -- including a gate that is merely equivalent-looking -- is refused, and
# the fix is to move the extra condition inside the job, as
# verify-latest-resolves-to-the-tag does for a pre-release tag.
#
# String equality on purpose: this file cannot evaluate a GitHub expression,
# and a check that guessed at what two different expressions mean would be a
# guard whose answer is a guess. An intentional divergence should fail here
# and be argued about, which is the point.

# release.yml has had at least this many jobs since the boot jobs landed in
# PR #287. The floor is a guard on the PARSE, not on the workflow's shape: a
# document that silently parsed to nothing would make every lookup below miss
# and report a rename, which is a wrong answer dressed as a real finding.
MINIMUM_JOBS = 5


def main() -> int:
    verbose = '--verbose' in sys.argv[1:]

    with open(WORKFLOW) as handle:
        workflow = yaml.safe_load(handle)

    jobs = (workflow or {}).get('jobs') or {}

    if len(jobs) < MINIMUM_JOBS:
        print(
            f'::error::{WORKFLOW} parsed to {len(jobs)} job(s), fewer than the '
            f'{MINIMUM_JOBS} this workflow has had since PR #287. The check '
            f'below would be reading an empty or wrongly-shaped document and '
            f'its answer would mean nothing.'
        )
        return 1

    if verbose:
        print(f'{WORKFLOW} parsed to {len(jobs)} jobs: {", ".join(sorted(jobs))}')

    for gated, dependency, why in REQUIRED_EDGES:
        for name in (gated, dependency):
            if name not in jobs:
                print(
                    f'::error::{WORKFLOW} has no `{name}` job. This check asserts '
                    f'that `{gated}` waits for `{dependency}`; if a job was '
                    f'renamed, rename it here too rather than leaving a check '
                    f'that passes because it can no longer find what it watched.'
                )
                return 1

        needs = jobs[gated].get('needs') or []
        if isinstance(needs, str):
            needs = [needs]

        if dependency not in needs:
            print(
                f'::error::{WORKFLOW}\'s `{gated}` job does not depend on '
                f'`{dependency}` (needs: {needs}). {why}'
            )
            return 1

        if verbose:
            print(f'{gated}.needs = {needs} -- includes {dependency}')

        gated_if = jobs[gated].get('if')
        dependency_if = jobs[dependency].get('if')

        if dependency_if is not None and str(dependency_if).strip() != str(gated_if).strip():
            print(
                f'::error::{WORKFLOW}\'s `{dependency}` job is gated '
                f'`if: {dependency_if}` while `{gated}`, which needs it, is '
                f'gated `if: {gated_if}`. A skipped `needs` dependency skips '
                f'its dependent (measured: '
                f'https://github.com/turbophp/doccum/actions/runs/35648660548), '
                f'so on a ref where `{dependency}` skips and `{gated}` would '
                f'not, `{gated}` silently does not run either. Move the extra '
                f'condition inside `{dependency}` and have it succeed with '
                f'nothing asserted -- the idiom changelog-section uses for a '
                f'non-tag ref, and verify-latest-resolves-to-the-tag for a '
                f'pre-release one.'
            )
            return 1

        if verbose:
            print(
                f'{dependency}.if = {dependency_if!r} -- '
                f'{"absent, so it never skips" if dependency_if is None else "identical to " + gated}'
            )

    print(
        f'{WORKFLOW}: all {len(REQUIRED_EDGES)} required needs-edges present '
        f'-- ' + '; '.join(f'{g} waits for {d}' for g, d, _ in REQUIRED_EDGES)
    )
    return 0


if __name__ == '__main__':
    sys.exit(main())
