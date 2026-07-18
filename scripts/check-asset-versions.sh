#!/usr/bin/env bash
#
# CI/deploy guard: fail if the entry points reference more than one asset
# cache-busting version (?v=N). A single version across all pages is required so
# a deploy never serves a stale CSS/JS mix.
#
# Covers TRACKED html AND php pages (the main SPA shell is api/index.php), via
# `git ls-files` so gitignored vendor/ is excluded. Keep in lockstep with
# scripts/bump-assets.sh, which rewrites the same file set.
#
# Usage: scripts/check-asset-versions.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

collect() {
  git ls-files '*.html' '*.php' | xargs grep -hoE '\?v=[0-9]+' 2>/dev/null || true
}

mapfile -t versions < <(collect | sort -u)

if [[ ${#versions[@]} -le 1 ]]; then
  echo "OK: single asset version in use: ${versions[0]:-<none>}"
  exit 0
fi

echo "ERROR: multiple asset versions found — run scripts/bump-assets.sh <N>." >&2
collect | sort | uniq -c >&2
exit 1
