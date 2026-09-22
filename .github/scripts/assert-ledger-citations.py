#!/usr/bin/env python3
"""item/citation-addresses-rot (issue #371): every `path:line` citation in an
item's doneWhen is declared, and the declared ones are checked.

WHY A LINE NUMBER IS A BAD ADDRESS, in this repository specifically.
decision/0080 makes a citation a claim that gets checked, so the reader is
expected to open the cited line. An insertion ANYWHERE above a citation
invalidates it, in a file the inserting pull request has no reason to open,
and there is no moment at which anyone is in a position to notice. That is not
hypothetical: item/release-v0-1-0 cited `release.yml:131` for the `latest`
rule, PR #359 inserted a job above it, and the rule moved to line 207 while the
citation stayed put. The likely outcome of a reader opening a cited line and
finding something unrelated is that they stop checking citations, which is the
whole defence.

SO THIS SCRIPT DOES TWO THINGS, and the first matters more than the second.

  1. It REFUSES an undeclared `path:line` in an item's doneWhen. Writing one
     now costs an entry in .github/ledger-citations.json, and that friction is
     the point: the cheaper path is to name an anchor that moves with the thing
     -- a function, a job, a heading, a quoted fragment -- which is what the
     item asks for and what needs no entry at all.

  2. For a declared citation that is meant to be accurate, it refuses when the
     recorded fragment is no longer at that line. That is the "or something
     checks the line numbers that remain" half of the same clause.

IT REFUSES RATHER THAN REPORTS, per decision/0113. A script that printed a
table of citations and their current lines would pass just as cheerfully with
every one of them rotted, and these runs get read through `| tail`.

SCOPE IS items[].doneWhen AND NOTHING ELSE, which is decision/0132 applied
rather than restated: an item's doneWhen is read for WHAT TO DO NOW, so a
rotted address in one is a defect; a Decision's rationale is read for WHAT WAS
TRUE THEN, so repointing a citation in one would replace a correct dated
observation with a wrong one. decision/0031 recording `login.blade.php:18` as
`type="email"` is the worked example -- it was true when written and became
`type="text"` when login-by-username shipped, and that is history, not rot.

WHAT THIS DOES NOT COVER. `path:line` is the only form this script sees. A
bare "line 131" in prose, whose file is named in an earlier clause or an
earlier sentence, is invisible to it. Both counts are PRINTED on every run --
the bare one plainly labelled as not checked -- rather than written into this
docblock, and that is a correction rather than a style preference: the first
version of this file stated both as numbers, and writing the ledger paragraph
that documents this guard added a citation, so the number was wrong one commit
later. A count copied into prose is a number that rots; see HANDOVER.md's
statement of the same rule, and item/citation-addresses-rot.

THE BARE FORM IS NOT THE ROT SURFACE THE COUNT SUGGESTS, audited 2026-09-22
and recorded in item/citation-addresses-rot. Not one bare citation in the
ledger is a live address: most sit in COMPLETED items, where a doneWhen
describes the state the item changed, and the rest are quotational -- examples
of rot, or a quotation held up in order to be refuted. So a guard demanding
declarations for them would catch nothing today. The convention in vocab.md
still binds anyone writing a NEW citation; enforcing it mechanically is worth
less than the count made it look.

NO GLOBBING, ANYWHERE, AND THE REASON IS EMBARRASSING. Python's glob skips
dot-directories unless include_hidden is set, so a survey written to find rot
reported `.github/scripts/build-docs-site.mjs` and `.github/workflows/
release.yml` as FILE NOT FOUND. That happened three times in this project --
twice in the surveys recorded in item/citation-addresses-rot, and once more in
the throwaway script written while designing this one. The inventory therefore
carries a full path per citation and this script only ever opens that path.
A full path is needed regardless: `Controller.php:56` matches two real files
in this tree, and only the fact that app/Http/Controllers/Api/V1/Controller.php
is 48 lines long says which one the prose meant.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LEDGER = ROOT / 'docs' / 'ledger' / 'ledger.jsonld'
INVENTORY = ROOT / '.github' / 'ledger-citations.json'

# A path-looking token ending in a known source extension, followed by :N.
# Deliberately narrow: a wider pattern matches "decision/0113" and version
# strings, and a guard that cries wolf gets deleted.
CITATION = re.compile(r'([\w./-]+\.(?:php|js|mjs|yml|yaml|md|json)):(\d+)')

# Reported, not enforced -- see count_bare_citations().
BARE_CITATION = re.compile(r'\blines?\s+\d+(?:\s*-\s*\d+)?', re.IGNORECASE)

REQUIRED_KEYS = {'item', 'citation', 'path', 'line', 'expectAtLine', 'why'}


def fail(errors: list[str]) -> None:
    print(f'::error::{len(errors)} citation problem(s) in docs/ledger/ledger.jsonld')
    for error in errors:
        print(f'  - {error}')
    sys.exit(1)


def found_in_ledger() -> dict[tuple[str, str], int]:
    """Every (item id, "path:line") pair appearing in an item's doneWhen."""
    ledger = json.loads(LEDGER.read_text())
    found: dict[tuple[str, str], int] = {}
    for item in ledger.get('items', []):
        item_id = item.get('@id')
        done_when = item.get('doneWhen')
        if not isinstance(item_id, str) or not isinstance(done_when, str):
            continue
        for match in CITATION.finditer(done_when):
            key = (item_id, match.group(0))
            found[key] = found.get(key, 0) + 1
    return found


def count_bare_citations() -> int:
    """Bare "line N" / "lines N-M" mentions, with path:line matches masked out
    first so "Directory.php:113-117" is not counted twice."""
    ledger = json.loads(LEDGER.read_text())
    total = 0
    for item in ledger.get('items', []):
        done_when = item.get('doneWhen')
        if not isinstance(done_when, str):
            continue
        masked = CITATION.sub(lambda m: '#' * len(m.group(0)), done_when)
        total += len(BARE_CITATION.findall(masked))
    return total


def main() -> None:
    errors: list[str] = []

    inventory = json.loads(INVENTORY.read_text())
    entries = inventory.get('citations')
    if not isinstance(entries, list):
        print(f'::error::{INVENTORY} has no "citations" list')
        sys.exit(1)

    declared: dict[tuple[str, str], dict] = {}
    for index, entry in enumerate(entries):
        if not isinstance(entry, dict):
            errors.append(f'citations[{index}] is not an object')
            continue

        missing = REQUIRED_KEYS - set(entry)
        if missing:
            errors.append(f'citations[{index}] is missing {sorted(missing)}')
            continue
        extra = set(entry) - REQUIRED_KEYS
        if extra:
            errors.append(f'citations[{index}] has unknown key(s) {sorted(extra)}')
            continue

        key = (entry['item'], entry['citation'])
        if key in declared:
            errors.append(f'{entry["item"]} declares "{entry["citation"]}" twice')
            continue
        declared[key] = entry

        citation_path, _, citation_line = entry['citation'].rpartition(':')

        # The entry explains the prose; it does not get to contradict it. A
        # path that does not end with what the citation says, or a line that
        # is not the one the citation names, would let the inventory check a
        # different address than the one a reader will open.
        if not entry['path'].endswith(citation_path):
            errors.append(
                f'{entry["item"]}: path "{entry["path"]}" does not end with '
                f'"{citation_path}" from the citation "{entry["citation"]}"'
            )
            continue
        if str(entry['line']) != citation_line:
            errors.append(
                f'{entry["item"]}: line {entry["line"]} does not match '
                f'"{entry["citation"]}"'
            )
            continue

        target = ROOT / entry['path']
        if not target.is_file():
            errors.append(f'{entry["item"]}: {entry["path"]} does not exist')
            continue

        expected = entry['expectAtLine']

        if expected is None:
            # A citation kept deliberately stale -- the sentence around it is
            # ABOUT the rot, so repointing it would destroy the example. It
            # owes an explanation instead of a check.
            if not isinstance(entry['why'], str) or not entry['why'].strip():
                errors.append(
                    f'{entry["item"]}: "{entry["citation"]}" has no expectAtLine, '
                    f'so it must say why in "why"'
                )
            continue

        if not isinstance(expected, str) or not expected.strip():
            errors.append(
                f'{entry["item"]}: "{entry["citation"]}" has an empty expectAtLine; '
                f'use null and a "why" for a citation that is not meant to be accurate'
            )
            continue

        lines = target.read_text().splitlines()
        if entry['line'] > len(lines):
            errors.append(
                f'{entry["item"]}: "{entry["citation"]}" points past the end of '
                f'{entry["path"]}, which has {len(lines)} lines'
            )
            continue

        actual = lines[entry['line'] - 1]
        if expected not in actual:
            errors.append(
                f'{entry["item"]}: "{entry["citation"]}" no longer holds what it '
                f'names. Expected to find {expected!r} at {entry["path"]}:'
                f'{entry["line"]}, which now reads {actual.strip()!r}. Repoint the '
                f'citation at an anchor that moves with the thing, or update this '
                f'entry if the line merely shifted.'
            )

    found = found_in_ledger()

    for key in sorted(found.keys() - declared.keys()):
        item_id, citation = key
        errors.append(
            f'{item_id} cites "{citation}" and nothing in '
            f'.github/ledger-citations.json declares it. Prefer an anchor that '
            f'moves with the thing -- a function, a job, a heading, a quoted '
            f'fragment -- which needs no entry; declare it here only if a line '
            f'number is genuinely what the sentence is about.'
        )

    for key in sorted(declared.keys() - found.keys()):
        item_id, citation = key
        errors.append(
            f'.github/ledger-citations.json declares "{citation}" for {item_id}, '
            f'which no longer cites it. Remove the entry.'
        )

    if errors:
        fail(errors)

    checked = sum(1 for e in declared.values() if e['expectAtLine'] is not None)
    print(
        f'{len(found)} path:line citation(s) across items[].doneWhen, all declared; '
        f'{checked} checked against the line they name, '
        f'{len(declared) - checked} deliberately stale with a stated reason.'
    )
    # Printed, never enforced, and labelled so: this script cannot resolve a
    # bare line number to a file, so it has nothing to check. It is here so
    # the figure lives in output that is regenerated on every run rather than
    # in prose that goes stale the next time anyone writes a sentence.
    print(
        f'{count_bare_citations()} bare "line N" citation(s) alongside them, '
        f'NOT CHECKED -- see item/citation-addresses-rot for why none of them '
        f'is a live address.'
    )


if __name__ == '__main__':
    main()
