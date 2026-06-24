#!/usr/bin/env bash
#
# CI/deploy guard: fail if the HTML entry points reference more than one
# asset cache-busting version (?v=N). A single version across all pages is
# required so a deploy never serves a stale CSS/JS mix.
#
# Usage: scripts/check-asset-versions.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

mapfile -t versions < <(grep -rhoE '\?v=[0-9]+' --include='*.html' . | sort -u || true)

if [[ ${#versions[@]} -le 1 ]]; then
  echo "OK: single asset version in use: ${versions[0]:-<none>}"
  exit 0
fi

echo "ERROR: multiple asset versions found in HTML — run scripts/bump-assets.sh <N>." >&2
grep -rhoE '\?v=[0-9]+' --include='*.html' . | sort | uniq -c >&2
exit 1
