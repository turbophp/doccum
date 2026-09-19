#!/usr/bin/env bash
#
# Prints the body of one version's section from CHANGELOG.md (Keep a
# Changelog format) -- exactly that section's body, never the heading line
# and never the following section.
#
# release.yml uses this output verbatim as a GitHub Release body, so the
# crux of this script is what it does when there is nothing to print:
# exits non-zero, naming the version, rather than returning an empty
# string. An extractor that returns empty on a missing section would let
# release.yml publish a release with a blank body, successfully, and nobody
# would notice until a user opened the release page.
#
# Usage: changelog-section.sh <version> [changelog-file]
#   <version>        e.g. "0.1.0", "v0.1.0" (a leading "v" is stripped)
#   [changelog-file] defaults to CHANGELOG.md in the current directory
#
# Matches both "## [1.2.3]" and "## [1.2.3] - 2026-01-01". It does NOT match
# "## [1.2.3-rc1]" when asked for "1.2.3", nor "## [0.1.0]" when asked for
# "0.1": the bracket has to close right after the version text.
#
# A PRE-RELEASE VERSION FALLS BACK TO ITS RELEASE SECTION, and the direction
# matters: "1.2.3-rc1" tries "## [1.2.3-rc1]" first and then "## [1.2.3]",
# while "1.2.3" still never matches "## [1.2.3-rc1]". That asymmetry is the
# point. item/release-v0-1-0 needs a throwaway pre-release tag to exercise
# the release pipeline end to end, and without this the `release` job would
# fail on a v0.1.0-rc1 tag AFTER the image had already been pushed --
# publishing a container and creating no Release. The alternative, writing a
# "## [0.1.0-rc1]" section into CHANGELOG.md for a tag nobody will keep,
# would put a lie in the changelog; an rc is a candidate for a release, so
# its notes ARE that release's notes.
#
# "Pre-release" here means anything after the release core -- a "-rc1"
# suffix or "+build.5" metadata alike -- because both name the same release.
#
# The fallback fires ONLY when the pre-release heading is absent, never when
# it is present-but-empty. A blank "## [1.2.3-rc1]" is somebody's mistake,
# and quietly publishing 1.2.3's notes under it would hide exactly the class
# of silence the exit codes below exist to prevent.
#
# NO REGEX IS USED FOR ANY OF THAT MATCHING, and that is deliberate rather
# than stylistic. An earlier version built an ERE containing [[:space:]] in
# a shell variable and handed it to awk as a DYNAMIC regex. It worked under
# mawk 1.3.4 20240123 locally and matched nothing under the awk on GitHub's
# runners, so the heading was found by grep, the body came back empty, and
# the script reported "has an empty body" for a section that plainly has
# one. POSIX character classes in a dynamic regex are not portable across
# awk implementations; literal prefix comparisons are. Keep it that way --
# this script's whole job is to be trustworthy about emptiness.
set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "usage: $(basename "$0") <version> [changelog-file]" >&2
  exit 2
fi

version="${1#v}"
file="${2:-CHANGELOG.md}"

if [ ! -f "$file" ]; then
  echo "changelog-section: changelog file not found: $file" >&2
  exit 1
fi

# One pass, no regex: find the heading by literal prefix, require what
# follows the closing bracket to be nothing or whitespace, collect until the
# next "## " heading, then trim blank lines from both ends. Exit codes:
# 0 body printed, 3 no such heading, 4 heading present but body blank.
extract_section() {
  awk -v want="## [${1}]" '
    function is_blank(s) { return s ~ /^[ \t\r]*$/ }
    found {
      if (substr($0, 1, 3) == "## ") { exit }
      lines[++n] = $0
      next
    }
    index($0, want) == 1 {
      rest = substr($0, length(want) + 1)
      first = substr(rest, 1, 1)
      if (rest == "" || first == " " || first == "\t" || first == "\r") { found = 1 }
    }
    END {
      if (!found) { exit 3 }
      start = 1; end = n
      while (start <= end && is_blank(lines[start])) start++
      while (end >= start && is_blank(lines[end])) end--
      if (start > end) { exit 4 }
      for (i = start; i <= end; i++) print lines[i]
    }
  ' "$file"
}

# The release core: everything before the first "-" or "+", so 1.2.3-rc1 and
# 1.2.3+build.5 both reduce to 1.2.3, and a plain 1.2.3 is left alone
# (the pattern simply does not match, and the value comes through unchanged).
core="${version%%[-+]*}"

set +e
body=$(extract_section "$version")
status=$?

# Only status 3 -- no such heading -- falls back, and only when there is a
# different version to fall back TO. Status 4 (present but blank) is a real
# error about the section that WAS found, and must not be papered over.
if [ "$status" -eq 3 ] && [ "$core" != "$version" ]; then
  body=$(extract_section "$core")
  status=$?
  if [ "$status" -eq 0 ]; then
    echo "changelog-section: no '## [${version}]' section; using '## [${core}]' for ${version}" >&2
  fi
  fellback="$core"
fi
set -e

# The failure messages name BOTH versions once a fallback was tried. Setting
# `version=$core` here instead would have reported "no '## [99.99.99]'
# section" to somebody who typed v99.99.99-rc1 -- an error naming a string
# they never wrote, sending them to look for the wrong heading. Say what was
# asked for and what was looked for.
looked_for="'## [${version}]'"
if [ -n "${fellback:-}" ]; then
  looked_for="${looked_for} (nor '## [${fellback}]', its release section)"
fi

case "$status" in
  0) ;;
  3) echo "changelog-section: no ${looked_for} section found in ${file}" >&2; exit 1 ;;
  4) echo "changelog-section: ${looked_for} section in ${file} has an empty body" >&2; exit 1 ;;
  *) echo "changelog-section: awk failed with status ${status} reading ${file}" >&2; exit 1 ;;
esac

printf '%s\n' "$body"
