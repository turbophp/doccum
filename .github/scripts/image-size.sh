#!/usr/bin/env bash
#
# Measures a built Docker image's size two ways and, once a budget has been
# recorded, gates on it. item/measure-image-size (issue #30), restated under
# decision/0087: the original acceptance named a budget that did not exist
# anywhere in this repository, which made it unfalsifiable. The budget is now
# DEFINED AS whatever this script measures on its own first run against a
# real build, recorded by hand into .github/image-budget.json with the commit
# it came from. After that, this script is the ratchet: it fails the run if
# a later image measures bigger than the recorded figure, in either metric.
#
# Usage: image-size.sh <image-tag>
#
# Two numbers, and they answer different questions:
#
#   - Uncompressed: `docker image inspect --format '{{.Size}}'`. This is the
#     size of the image unpacked on disk -- what `docker images` shows,
#     roughly what landing the image on a host costs once extracted.
#
#   - "Compressed": `docker save <tag> | gzip -c | wc -c`. This is a single
#     gzip pass over the WHOLE `docker save` tarball (every layer
#     concatenated, uncompressed, into one archive, then gzipped once as a
#     unit). It is NOT the same number as a registry pull: a real
#     `docker pull` fetches each layer's own independently-gzipped blob, and
#     shared/cached layers on the pulling side are not re-downloaded at all.
#     This script's compressed figure is a stable, locally-computable proxy
#     for "did this image get bigger", not a promise about download time --
#     it is deliberately never called "the pull size" anywhere below.
#
# Parsing .github/image-budget.json: not with jq (not confirmed present on
# every runner this is wired into) and not with a hand-rolled awk/sed regex
# (CLAUDE.md already records four CI cycles lost to an awk dialect
# difference between a local shell and GitHub's runners over exactly this
# kind of parsing -- see changelog-section.sh's header for the full story).
# python3 is definitely present on GitHub-hosted runners, so it does the one
# thing that needs real JSON parsing.
set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "usage: $(basename "$0") <image-tag>" >&2
  exit 2
fi

tag="$1"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
budget_file="${script_dir}/../image-budget.json"

if [ ! -f "$budget_file" ]; then
  echo "image-size: budget file not found: $budget_file" >&2
  exit 1
fi

uncompressed_bytes="$(docker image inspect --format '{{.Size}}' "$tag")"
compressed_bytes="$(docker save "$tag" | gzip -c | wc -c | tr -d ' ')"

# Plain arithmetic, no bc/awk float games: MiB shown to one decimal place
# using only integer division, which every shell here supports the same way.
to_mib_1dp() {
  bytes="$1"
  whole=$((bytes / 1048576))
  # tenths = floor(bytes * 10 / 1048576) mod 10
  tenths=$(((bytes * 10 / 1048576) % 10))
  printf '%d.%d' "$whole" "$tenths"
}

uncompressed_mib="$(to_mib_1dp "$uncompressed_bytes")"
compressed_mib="$(to_mib_1dp "$compressed_bytes")"

echo "image: $tag"
echo "uncompressed size (docker image inspect .Size): ${uncompressed_bytes} bytes (${uncompressed_mib} MiB)"
echo "compressed size (gzip of the full 'docker save' tarball -- NOT a registry pull size, see this script's header): ${compressed_bytes} bytes (${compressed_mib} MiB)"

# Read the recorded budget. Emits three lines on stdout: measuredAtCommit,
# recorded uncompressedBytes, recorded compressedBytes -- in that order, so
# the shell below can read them positionally without re-parsing JSON itself.
budget_fields="$(python3 -c '
import json
import sys

with open(sys.argv[1]) as f:
    budget = json.load(f)

print(budget["measuredAtCommit"])
print(budget["uncompressedBytes"])
print(budget["compressedBytes"])
' "$budget_file")"

recorded_commit="$(echo "$budget_fields" | sed -n '1p')"
recorded_uncompressed="$(echo "$budget_fields" | sed -n '2p')"
recorded_compressed="$(echo "$budget_fields" | sed -n '3p')"

if [ "$recorded_commit" = "UNMEASURED" ]; then
  echo "no budget recorded yet (measuredAtCommit is UNMEASURED in $budget_file) -- gate is INACTIVE; these measured numbers are the candidate first record. Copy them into $budget_file by hand along with this commit's SHA to start enforcing the ratchet."
  exit 0
fi

echo "recorded budget: ${recorded_uncompressed} uncompressed bytes, ${recorded_compressed} compressed bytes, measured at commit ${recorded_commit}"

failed=0

if [ "$uncompressed_bytes" -gt "$recorded_uncompressed" ]; then
  over=$((uncompressed_bytes - recorded_uncompressed))
  echo "::error::uncompressed image size ${uncompressed_bytes} bytes exceeds the recorded budget of ${recorded_uncompressed} bytes (over by ${over} bytes) -- recorded at commit ${recorded_commit}. Either shrink the image back under budget, or record a Decision explaining the growth and raise the recorded figure in $budget_file."
  failed=1
fi

if [ "$compressed_bytes" -gt "$recorded_compressed" ]; then
  over=$((compressed_bytes - recorded_compressed))
  echo "::error::compressed image size ${compressed_bytes} bytes exceeds the recorded budget of ${recorded_compressed} bytes (over by ${over} bytes) -- recorded at commit ${recorded_commit}. Either shrink the image back under budget, or record a Decision explaining the growth and raise the recorded figure in $budget_file."
  failed=1
fi

if [ "$failed" -ne 0 ]; then
  exit 1
fi

echo "image size is within the recorded budget"
