#!/usr/bin/env python3
"""Assert that release.yml's `release` job waits for `boot-published-image`.

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
GATED_JOB = 'release'
REQUIRED_DEPENDENCY = 'boot-published-image'

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

    for name in (GATED_JOB, REQUIRED_DEPENDENCY):
        if name not in jobs:
            print(
                f'::error::{WORKFLOW} has no `{name}` job. This check asserts '
                f'that `{GATED_JOB}` waits for `{REQUIRED_DEPENDENCY}`; if a job '
                f'was renamed, rename it here too rather than leaving a check '
                f'that passes because it can no longer find what it watched. '
                f'See issue #303.'
            )
            return 1

    needs = jobs[GATED_JOB].get('needs') or []
    if isinstance(needs, str):
        needs = [needs]

    if REQUIRED_DEPENDENCY not in needs:
        print(
            f'::error::{WORKFLOW}\'s `{GATED_JOB}` job does not depend on '
            f'`{REQUIRED_DEPENDENCY}` (needs: {needs}). The GitHub Release would '
            f'be published in parallel with the job proving the image boots, so '
            f'a failing arm64 leg would arrive after the announcement was '
            f'already public. See issue #303, and item/release-v0-1-0, which '
            f'says to drop linux/arm64 rather than ship a manifest nobody has '
            f'booted.'
        )
        return 1

    print(f'{GATED_JOB}.needs = {needs} -- the Release waits for the boot check')
    return 0


if __name__ == '__main__':
    sys.exit(main())
