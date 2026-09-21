#!/usr/bin/env python3
"""Assert Run.merges names every workflow a push to `main` triggers.

Issue #375. `item/ledger-main-push-record` chose named fields over a list and
justified it: "Two NAMED workflow fields rather than a list, because LOOP step
3 names exactly `tests` and `ledger` and those are the whole of what a main
push triggers, so a closed pair cannot under-report where a list is only as
complete as its caller."

The premise was false. `pages.yml` is `on: push: branches: [main]` and was
never in that enumeration, so the schema was a list-of-two wearing the
authority of a complete set -- which is worse than a list, because a list at
least admits it is only as complete as its caller. A red `pages` build on
`main` had nowhere to be recorded and nothing that would notice.

A closed set cannot under-report ONLY IF THE SET IS RIGHT. Nothing checked
that, because the set lived in prose: in LOOP.md's enumeration, in an item's
doneWhen, and in four hardcoded pairs inside LedgerValidator. This script is
the thing that checks it. It reads the workflows and the constant and refuses
unless they agree, in both directions.

decision/0113: when a tool can be invoked in a way that checks nothing, make
it REFUSE rather than announce. So every step below that could come back empty
-- no workflow files, no `on:` key, no constant in the PHP -- is an error with
its own message. A guard that silently finds nothing is indistinguishable from
a guard that found everything in order, and only one of them is green for a
reason.
"""

from __future__ import annotations

import glob
import re
import sys

import yaml

WORKFLOW_GLOB = '.github/workflows/*.yml'
VALIDATOR = 'app/Support/LedgerValidator.php'
CONSTANT = 'MAIN_PUSH_WORKFLOWS'
MAIN = 'main'

# This repository has had at least this many workflows since pages.yml landed.
# A floor on the PARSE, not on the shape: a glob that matched nothing would
# make the computed set empty and report "the ledger names three workflows
# that do not exist", which is a wrong answer dressed as a real finding.
MINIMUM_WORKFLOWS = 4


def matches(pattern: str, ref: str) -> bool:
    """GitHub's filter-pattern matching, narrowed to what this repository uses.

    `*` does not cross a `/`, `**` does. Written out rather than handed to
    fnmatch, which treats `*` as crossing separators and would make
    `claude/**` -- and, worse, a future `*` -- match `main`.
    """
    if not any(c in pattern for c in '*?['):
        return pattern == ref

    regex, i = '', 0
    while i < len(pattern):
        c = pattern[i]
        if pattern.startswith('**', i):
            regex += '.*'
            i += 2
        elif c == '*':
            regex += '[^/]*'
            i += 1
        elif c == '?':
            regex += '[^/]'
            i += 1
        else:
            regex += re.escape(c)
            i += 1
    return re.fullmatch(regex, ref) is not None


def triggers_on_main_push(document: dict) -> bool:
    """True when this workflow runs on a push to `main`.

    `on` is YAML 1.1's boolean `true`, so PyYAML parses the key as True
    rather than the string. Both spellings are accepted rather than one
    guessed at -- getting this wrong would make every workflow look like it
    has no triggers, which is the silent-empty failure this file exists to
    avoid.

    THE TAG-ONLY CASE IS THE ONE THAT BIT. A first draft read "no `branches`
    key" as "every branch" and so counted release.yml, which is
    `push: tags: [v*]`. GitHub runs a push trigger carrying `tags` and no
    `branches` for those TAG pushes only, never for a branch push -- and
    release.yml is precisely the workflow whose runs must not be recorded as
    a merge's verification, because no merge produces one. Caught by running
    this script before wiring it up, which is the only reason it is written
    down here rather than shipped.
    """
    triggers = document.get('on', document.get(True))
    if not isinstance(triggers, dict):
        return False

    if 'push' not in triggers:
        return False

    push = triggers['push']
    if not isinstance(push, dict):
        # `push:` with no body at all means every branch, `main` included.
        return True

    if 'branches' in push:
        return any(matches(str(p), MAIN) for p in (push['branches'] or []))

    if 'branches-ignore' in push:
        return not any(matches(str(p), MAIN) for p in (push['branches-ignore'] or []))

    if 'tags' in push or 'tags-ignore' in push:
        # Tag filters and no branch filter: this fires for tag pushes only.
        return False

    # A push trigger with no ref filters at all fires on every branch.
    return True


def recorded_workflows() -> list[str]:
    with open(VALIDATOR) as handle:
        source = handle.read()

    match = re.search(
        rf'const\s+{CONSTANT}\s*=\s*\[(.*?)\]\s*;',
        source,
        re.DOTALL,
    )
    if match is None:
        print(
            f'::error::{VALIDATOR} has no `{CONSTANT}` constant. This check '
            f'compares that list against the workflows a main push triggers; '
            f'without it there is nothing to compare, and reporting success '
            f'would mean "not found" rather than "they agree".'
        )
        sys.exit(1)

    names = re.findall(r"'([^']+)'", match.group(1))
    if not names:
        print(f'::error::{VALIDATOR}\'s `{CONSTANT}` parsed to an empty list.')
        sys.exit(1)
    return names


def main() -> int:
    verbose = '--verbose' in sys.argv[1:]

    paths = sorted(glob.glob(WORKFLOW_GLOB))
    if len(paths) < MINIMUM_WORKFLOWS:
        print(
            f'::error::{WORKFLOW_GLOB} matched {len(paths)} file(s), fewer than '
            f'the {MINIMUM_WORKFLOWS} this repository has. The comparison below '
            f'would be reading an empty set and its answer would mean nothing.'
        )
        return 1

    on_main_push = set()
    for path in paths:
        with open(path) as handle:
            document = yaml.safe_load(handle)
        if isinstance(document, dict) and triggers_on_main_push(document):
            on_main_push.add(path.rsplit('/', 1)[-1].removesuffix('.yml'))

    if not on_main_push:
        print(
            f'::error::no workflow in {WORKFLOW_GLOB} parsed as triggering on a '
            f'push to {MAIN}, which cannot be true -- this repository runs CI on '
            f'every merge. The `on:` parse is wrong, so this check proved nothing.'
        )
        return 1

    recorded = set(recorded_workflows())

    if verbose:
        print(f'{len(paths)} workflow files; on a push to {MAIN}: {", ".join(sorted(on_main_push))}')
        print(f'{VALIDATOR}::{CONSTANT} = {", ".join(sorted(recorded))}')

    unrecorded = on_main_push - recorded
    if unrecorded:
        print(
            f'::error::{", ".join(sorted(unrecorded))} run(s) on a push to {MAIN} '
            f'but Run.merges has nowhere to record them. Every merge entry names '
            f'one field pair per workflow, and the schema claims to be a CLOSED '
            f'set -- so a workflow missing from it is a red run on {MAIN} that no '
            f'ledger rule can see. Add `{CONSTANT}` entries in {VALIDATOR}, add '
            f'the field pair to every entry in docs/ledger/runs/, and update '
            f'docs/LOOP.md and docs/ledger/vocab.md. See issue #375, which is '
            f'this exact failure with pages.yml.'
        )
        return 1

    phantom = recorded - on_main_push
    if phantom:
        print(
            f'::error::{VALIDATOR}::{CONSTANT} names {", ".join(sorted(phantom))}, '
            f'which no longer trigger(s) on a push to {MAIN}. Either the workflow '
            f'was renamed or retriggered, or the constant was not updated with it. '
            f'A field pair nobody writes is not harmless: it makes every merge '
            f'entry carry a null that reads like "this run was not recorded".'
        )
        return 1

    print(
        f'Run.merges covers every workflow a push to {MAIN} triggers: '
        f'{", ".join(sorted(on_main_push))}.'
    )
    return 0


if __name__ == '__main__':
    sys.exit(main())
