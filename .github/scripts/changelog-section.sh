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
# Matches both "## [1.2.3]" and "## [1.2.3] - 2026-01-01" -- anything after
# the closing bracket, as long as it is separated by whitespace, is ignored.
# It does NOT match "## [1.2.3-rc1]" when asked for "1.2.3": the bracket has
# to close right after the version text.
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

# The only characters a semver version can contain that are also basic-regex
# metacharacters are dots; escape defensively rather than assume that holds.
escaped_version=$(printf '%s' "$version" | sed -e 's/[.[\*^$]/\\&/g')

heading_pattern="^## \\[${escaped_version}\\]([[:space:]].*)?\$"

if ! grep -E -q "$heading_pattern" "$file"; then
  echo "changelog-section: no '## [${version}]' section found in ${file}" >&2
  exit 1
fi

# One pass: skip everything up to and including the matching heading, collect
# every line up to (not including) the next "## " heading or end of file,
# then trim leading/trailing blank lines so a section that is heading-only
# (all blank lines) is detected as empty below.
body=$(awk -v pat="$heading_pattern" '
  found {
    if ($0 ~ /^## /) exit
    lines[++n] = $0
    next
  }
  $0 ~ pat { found = 1 }
  END {
    start = 1
    end = n
    while (start <= end && lines[start] ~ /^[[:space:]]*$/) start++
    while (end >= start && lines[end] ~ /^[[:space:]]*$/) end--
    for (i = start; i <= end; i++) print lines[i]
  }
' "$file")

if [ -z "$(printf '%s' "$body" | tr -d '[:space:]')" ]; then
  echo "changelog-section: '## [${version}]' section in ${file} has an empty body" >&2
  exit 1
fi

printf '%s\n' "$body"
