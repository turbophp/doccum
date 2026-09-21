#!/usr/bin/env python3
"""Assert that release.yml's `boot-published-image` job actually wires up
the full container smoke against the image it just booted.

Issue #340 (second box). tests.yml's `image` job proves upload, extraction,
search, trash and purge -- but only against an image `docker build` produced
on the runner. release.yml's `boot-published-image` job pulls the image a
self-hoster would actually get from ghcr and asserts exactly one string
against it ("Set up doccum"). Nothing before this had ever driven upload,
search, trash or purge against the artifact this project actually ships.

WHY A STATIC ASSERTION RATHER THAN A MUTATION. release.yml fires only on a
tag push or a workflow_dispatch (see its `on:` block). This project has no
tag, and boot-published-image is itself guarded to
`startsWith(github.ref, 'refs/tags/')`, so no run available here ever
executes that job -- there is nothing to observe pass or fail by running it.
Left as a comment, "the published-image job runs the full smoke, pointed at
the container it booted" would be a claim nothing checks. decision/0113:
when a guard cannot be exercised, make something REFUSE rather than
describe and hope.

WHAT THIS ACTUALLY CATCHES. Not "is container-smoke.mjs invoked" -- that
alone proves nothing, because a smoke pointed at CONTAINER_NAME=doccum-smoke
(the OTHER job's container, or last release's leftover name) would still run
green while asserting against a container that was never booted here, or
against nothing at all. So this extracts the container name and port the
`docker run` step in THIS job actually creates, and the CONTAINER_NAME/
BASE_URL the smoke step's own `env:` supplies, and compares them -- not a
hard-coded expectation of either value, because the point is that the two
agree with EACH OTHER, wherever they are set from.

WHY yaml.safe_load RATHER THAN grep, for the same reason as
assert-release-gate.py: reordering the job's steps, reflowing a `run:` block
between one-line and block-scalar form, or moving `env:` keys around are all
the same graph and must all still pass; a name or port that actually
disagrees must fail however the YAML happens to be laid out. A grep for a
literal string answers a question about formatting instead.

WHY THE SEVEN SMOKE_* FIXTURES ARE PARSED OUT OF THE JS RATHER THAN LISTED
HERE. release.yml's job duplicates tests.yml's fixture-generation step
instead of sharing it (see the comment on that duplication in release.yml).
A hard-coded list of "the seven required variables" in this script would
rot the exact way a hard-coded list in release.yml would: add an eighth
required SMOKE_* variable to container-smoke.mjs and both the workflow and
this checker would go on agreeing with a fixture list that is now wrong.
Parsing container-smoke.mjs's own `env(...)` calls means a new required
variable makes this check red on the next PR, not silently unsupplied on
the next tag push.
"""

from __future__ import annotations

import re
import sys
from urllib.parse import urlparse

import yaml

WORKFLOW = '.github/workflows/release.yml'
SMOKE_SCRIPT = '.github/scripts/container-smoke.mjs'
JOB = 'boot-published-image'

# release.yml has had at least this many jobs since the boot jobs landed in
# PR #287 (see assert-release-gate.py, which asserts the same floor against
# the same file). The floor is a guard on the PARSE, not on the workflow's
# shape: a document that silently parsed to nothing would make every lookup
# below miss and report a rename, which is a wrong answer dressed as a real
# finding.
MINIMUM_JOBS = 5

# `env('NAME')` -- no second argument, so container-smoke.mjs's own env()
# throws "Missing required environment variable NAME" if it is unset. Only
# SMOKE_-prefixed names: BASE_URL and CONTAINER_NAME are checked separately
# and by a different rule (point 2/3 below), because their expected VALUES
# have to agree with the `docker run` step, not merely be "set to something".
REQUIRED_SMOKE_VAR = re.compile(r"""env\(\s*['"](SMOKE_[A-Z0-9_]+)['"]\s*\)""")
DEFAULTED_SMOKE_VAR = re.compile(r"""env\(\s*['"](SMOKE_[A-Z0-9_]+)['"]\s*,""")

# A single YAML-expression-aware "word": either one `${{ ... }}` block (which
# may itself contain spaces, e.g. `${{ matrix.arch }}`) taken whole, or one
# non-whitespace character. Repeated, this lets a plain regex pull a
# `--name`/`-p` argument out of a shell command without choking on the
# spaces inside a GitHub Actions expression the way `\S+` would.
_TOKEN = r'(?:\$\{\{.*?\}\}|\S)+'
NAME_FLAG = re.compile(r'--name\s+(' + _TOKEN + r')')
PUBLISH_FLAG = re.compile(r'-p\s+(' + _TOKEN + r')')
ECHO_TO_GITHUB_ENV = re.compile(r'echo\s+"(SMOKE_[A-Z0-9_]+)=')


def normalize(token: str) -> str:
    """Collapse incidental whitespace so `${{ matrix.arch }}` still equals
    itself after a `run:` block is reflowed or re-indented."""
    return re.sub(r'\s+', ' ', token.strip())


def main() -> int:
    verbose = '--verbose' in sys.argv[1:]

    with open(WORKFLOW) as handle:
        workflow = yaml.safe_load(handle)

    jobs = (workflow or {}).get('jobs') or {}

    if len(jobs) < MINIMUM_JOBS:
        print(
            f'::error::{WORKFLOW} parsed to {len(jobs)} job(s), fewer than the '
            f'{MINIMUM_JOBS} this workflow has had since PR #287. The checks '
            f'below would be reading an empty or wrongly-shaped document and '
            f'their answer would mean nothing.'
        )
        return 1

    if verbose:
        print(f'{WORKFLOW} parsed to {len(jobs)} jobs: {", ".join(sorted(jobs))}')

    if JOB not in jobs:
        print(
            f'::error::{WORKFLOW} has no `{JOB}` job. This check asserts that '
            f'job runs the full container smoke against the image it pulled; '
            f'if it was renamed, rename it here too rather than leaving a '
            f'check that passes because it can no longer find what it '
            f'watched. See issue #340.'
        )
        return 1

    steps = jobs[JOB].get('steps') or []

    # --- 1. a step invokes container-smoke.mjs -----------------------------

    smoke_steps = [s for s in steps if 'container-smoke.mjs' in (s.get('run') or '')]

    if not smoke_steps:
        print(
            f'::error::`{JOB}` has no step whose `run` invokes container-smoke.mjs. '
            f'This job proves the PUBLISHED image only by asserting one string '
            f'("Set up doccum"); nothing exercises upload, search, trash or '
            f'purge against it. See issue #340.'
        )
        return 1

    if len(smoke_steps) > 1:
        print(
            f'::error::`{JOB}` has {len(smoke_steps)} steps invoking '
            f'container-smoke.mjs; this check assumes exactly one so its '
            f'CONTAINER_NAME/BASE_URL comparison below is unambiguous. '
            f'Collapse to one step, or extend this script to check each.'
        )
        return 1

    smoke_step = smoke_steps[0]
    smoke_env = smoke_step.get('env') or {}

    if verbose:
        print(f'container-smoke.mjs invoked by step {smoke_step.get("name")!r}')

    # --- 2 & 3. CONTAINER_NAME and BASE_URL's port match the boot step -----

    boot_steps = [s for s in steps if '--name' in (s.get('run') or '') and 'docker run' in (s.get('run') or '')]

    if not boot_steps:
        print(
            f'::error::`{JOB}` has no step running `docker run --name ...`. '
            f'Nothing boots a named container for the smoke to point at. '
            f'See issue #340.'
        )
        return 1

    if len(boot_steps) > 1:
        print(
            f'::error::`{JOB}` has {len(boot_steps)} steps running '
            f'`docker run --name ...`; this check assumes exactly one boot '
            f'step to compare the smoke against. Collapse to one, or extend '
            f'this script.'
        )
        return 1

    boot_run = boot_steps[0]['run']

    name_match = NAME_FLAG.search(boot_run)
    if not name_match:
        print(
            f'::error::could not find a `--name` argument in `{JOB}`\'s boot '
            f'step -- the regex used to extract it did not match, so this '
            f'check cannot compare it to anything.'
        )
        return 1
    booted_name = normalize(name_match.group(1))

    publish_match = PUBLISH_FLAG.search(boot_run)
    if not publish_match:
        print(
            f'::error::could not find a `-p` (publish) argument in `{JOB}`\'s '
            f'boot step -- the regex used to extract it did not match, so '
            f'this check cannot compare its port to BASE_URL.'
        )
        return 1
    publish_spec = normalize(publish_match.group(1))
    if ':' not in publish_spec:
        print(
            f'::error::`{JOB}`\'s boot step publishes `-p {publish_spec}`, '
            f'which has no host:container port pair to compare against '
            f'BASE_URL.'
        )
        return 1
    booted_host_port = publish_spec.split(':', 1)[0]

    smoke_container_name = smoke_env.get('CONTAINER_NAME')
    if smoke_container_name is None:
        print(
            f'::error::the container-smoke.mjs step in `{JOB}` sets no '
            f'`env.CONTAINER_NAME` -- container-smoke.mjs would fall back to '
            f"its own default ('doccum-smoke'), a container this job never "
            f'boots. See issue #340.'
        )
        return 1

    if normalize(str(smoke_container_name)) != booted_name:
        print(
            f'::error::`{JOB}`\'s boot step creates a container named '
            f'{booted_name!r} (`docker run --name ...`), but the '
            f'container-smoke.mjs step sets CONTAINER_NAME={smoke_container_name!r} -- '
            f'the smoke would be pointed at a container this job never '
            f'booted. See issue #340.'
        )
        return 1

    smoke_base_url = smoke_env.get('BASE_URL')
    if smoke_base_url is None:
        print(
            f'::error::the container-smoke.mjs step in `{JOB}` sets no '
            f'`env.BASE_URL` -- container-smoke.mjs would fall back to its '
            f"own default port, which is not guaranteed to be the port this "
            f'job actually published. See issue #340.'
        )
        return 1

    parsed_base_url = urlparse(str(smoke_base_url))
    smoke_port = str(parsed_base_url.port) if parsed_base_url.port else None
    if smoke_port is None:
        print(
            f'::error::could not read a port out of BASE_URL={smoke_base_url!r} '
            f'set by the container-smoke.mjs step in `{JOB}`.'
        )
        return 1

    if smoke_port != booted_host_port:
        print(
            f'::error::`{JOB}`\'s boot step publishes host port '
            f'{booted_host_port!r} (`-p {publish_spec}`), but the '
            f'container-smoke.mjs step\'s BASE_URL={smoke_base_url!r} points '
            f'at port {smoke_port!r} -- the smoke would talk to the wrong '
            f'port, or nothing at all. See issue #340.'
        )
        return 1

    if verbose:
        print(
            f'CONTAINER_NAME={booted_name!r} and BASE_URL port {smoke_port!r} '
            f'both match the `docker run` step in `{JOB}`'
        )

    # --- 4. every no-default SMOKE_* var in the JS is supplied here --------

    with open(SMOKE_SCRIPT) as handle:
        smoke_js = handle.read()

    defaulted = set(DEFAULTED_SMOKE_VAR.findall(smoke_js))
    # A name matched by both patterns is genuinely required (findall for the
    # no-default form is the authority); a call site can only be one or the
    # other, so this only guards against a pathological regex overlap.
    required = set(REQUIRED_SMOKE_VAR.findall(smoke_js)) - defaulted

    if not required:
        print(
            f'::error::found zero `env(\'SMOKE_...\')` calls with no default '
            f'in {SMOKE_SCRIPT}. Either the regex stopped matching (a rename, '
            f'a reformatted call) or the file changed so nothing is required '
            f"any more -- either way this check cannot silently read that as "
            f"\"all satisfied\"."
        )
        return 1

    if verbose:
        print(f'{SMOKE_SCRIPT} requires (no default): {", ".join(sorted(required))}')

    supplied = set(smoke_env)
    for step in steps:
        supplied |= set(ECHO_TO_GITHUB_ENV.findall(step.get('run') or ''))

    missing = sorted(required - supplied)
    if missing:
        print(
            f'::error::`{JOB}` never supplies {", ".join(missing)}, which '
            f'container-smoke.mjs requires with no default and would throw '
            f'on. container-smoke.mjs gained a required SMOKE_* variable (or '
            f'{JOB} lost the fixture step that wrote it) since these two '
            f'were last brought back into agreement. See issue #340.'
        )
        return 1

    print(
        f'`{JOB}` invokes container-smoke.mjs against the container it '
        f'booted (name {booted_name!r}, port {smoke_port!r}), and supplies '
        f'all {len(required)} SMOKE_* variables container-smoke.mjs requires '
        f'with no default: {", ".join(sorted(required))}'
    )
    return 0


if __name__ == '__main__':
    sys.exit(main())
