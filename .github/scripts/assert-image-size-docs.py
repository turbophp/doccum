#!/usr/bin/env python3
"""Assert README.md's image-size table still agrees with .github/image-budget.json.

The two files hold the same three facts -- the uncompressed byte count, the
compressed byte count and the commit they were measured at -- and only the
budget file is enforced. image-size.sh gates every build against it, and its
own note says raising a figure "takes a Decision saying why". Nothing made the
README follow, so the supported way to raise the ceiling was also a way to
leave the README quoting a number that is no longer true, silently, in the
one file a self-hoster reads before pulling anything.

decision/0125: a check must synchronise on everything it asserts, not a
subset. The budget file asserts a size; the README asserts THE SAME size to a
different audience; a gate on one of them is a gate on one of them.

WHAT THIS DOES NOT DO: measure anything. It never builds an image and never
reads Docker. It compares two files in the tree, so it runs in a job with no
`needs:` in about a second, and it is deliberately blind to whether the
recorded budget is itself correct -- image-size.sh owns that, against a real
build.

decision/0113, applied to this script's own failure mode: a checker that
cannot find its subject must REFUSE, not pass quietly. If the README's table
is restructured so the patterns below match nothing, that is indistinguishable
from a README with no numbers in it at all -- and a check that silently
approves an absence is worse than no check, because the next reader sees a
green tick. So a missing table, a missing row, or a byte figure that appears
zero times is an error with its own message, not a skip.
"""

from __future__ import annotations

import json
import re
import sys

README = 'README.md'
BUDGET = '.github/image-budget.json'


def mib_1dp(byte_count: int) -> str:
    """Reproduce image-size.sh's to_mib_1dp exactly.

    Integer arithmetic only, the same way that script does it -- so the
    README's MiB figures are checked to be DERIVED from the recorded byte
    counts rather than typed alongside them. A README that rounded 934.24 to
    934.3 by hand would disagree with what CI prints for the same image, and
    the two would drift apart while both looked plausible.
    """
    return f'{byte_count // 1048576}.{(byte_count * 10 // 1048576) % 10}'


def main() -> int:
    verbose = '--verbose' in sys.argv[1:]

    with open(BUDGET) as handle:
        budget = json.load(handle)

    with open(README) as handle:
        readme = handle.read()

    errors: list[str] = []

    # The table this checks lives under a heading; if the heading is gone the
    # rest of this script is reading a document it was not written for.
    if '## Image size' not in readme:
        print(
            f'::error::{README} has no "## Image size" section. Every check '
            f'below reads that table, so their silence would mean "not found", '
            f'not "agrees with {BUDGET}".'
        )
        return 1

    checks = [
        ('uncompressedBytes', int(budget['uncompressedBytes'])),
        ('compressedBytes', int(budget['compressedBytes'])),
    ]

    for field, byte_count in checks:
        # As written for a human: 979,591,435. Both spellings are accepted so
        # the README can drop the separators later without this going red for
        # a formatting choice.
        spellings = {f'{byte_count:,}', str(byte_count)}
        if not any(spelling in readme for spelling in spellings):
            errors.append(
                f'{BUDGET} records {field} = {byte_count:,}, and {README} does '
                f'not contain that number in any spelling. Either the budget '
                f'was raised without updating the README -- which leaves a '
                f'self-hoster reading a size this project no longer builds -- '
                f'or the README was edited to a figure nothing enforces.'
            )
            continue

        expected_mib = mib_1dp(byte_count)
        if expected_mib not in readme:
            errors.append(
                f'{README} quotes {byte_count:,} bytes for {field} but not '
                f'"{expected_mib}" MiB, which is what image-size.sh prints for '
                f'that byte count. The MiB figure has to be derived from the '
                f'bytes, not typed next to them, or CI and the README will '
                f'disagree about the same image while both look plausible.'
            )

        if verbose:
            print(f'{field}: {byte_count:,} bytes = {expected_mib} MiB -- both present in {README}')

    recorded_commit = str(budget['measuredAtCommit'])
    if recorded_commit != 'UNMEASURED':
        # The row is `| Measured at commit | `sha` | `sha` |`. Counted rather
        # than merely found: the table has one column per metric, so a README
        # that updated only one of them would still contain the sha once.
        occurrences = len(re.findall(re.escape(recorded_commit), readme))
        if occurrences < 2:
            errors.append(
                f'{BUDGET} was measured at commit {recorded_commit}, and '
                f'{README} names that commit {occurrences} time(s) -- the '
                f'table has a column per metric and so should name it twice. '
                f'A README that updated one column and not the other reports '
                f'two figures from two different builds as one measurement.'
            )
        elif verbose:
            print(f'measuredAtCommit {recorded_commit} named {occurrences} times in {README}')

    if errors:
        for error in errors:
            print(f'::error::{error}')
        return 1

    print(
        f'{README} and {BUDGET} agree: '
        f'{int(budget["uncompressedBytes"]):,} bytes uncompressed '
        f'({mib_1dp(int(budget["uncompressedBytes"]))} MiB), '
        f'{int(budget["compressedBytes"]):,} compressed '
        f'({mib_1dp(int(budget["compressedBytes"]))} MiB), '
        f'measured at {recorded_commit}.'
    )
    return 0


if __name__ == '__main__':
    sys.exit(main())
