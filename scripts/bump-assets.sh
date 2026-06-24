#!/usr/bin/env bash
#
# Single source of truth for the static asset cache-busting version (?v=N).
#
# All HTML entry points (api/index.html, login.html, admin/index.html,
# help/*, legal/*) reference CSS/JS with a `?v=N` query string. Historically
# these drifted out of sync (index at v=25, others at v=22), so a deploy could
# serve a stale mix. This script rewrites EVERY `?v=N` across all tracked HTML
# to a single value — bump it once and every page refreshes consistently.
#
# Usage:
#   scripts/bump-assets.sh <integer>     # set all assets to ?v=<integer>
#   scripts/bump-assets.sh               # show current versions in use
#
set -euo pipefail
cd "$(dirname "$0")/.."

show_current() {
  echo "Asset versions currently in use:"
  # `|| true`: with `set -o pipefail`, grep exiting non-zero on no matches
  # would otherwise abort the script instead of printing an empty summary.
  { grep -rhoE '\?v=[0-9]+' --include='*.html' . || true; } | sort | uniq -c
}

NEW="${1:-}"
if [[ -z "$NEW" ]]; then
  show_current
  echo
  echo "Usage: scripts/bump-assets.sh <integer-version>"
  exit 0
fi

if ! [[ "$NEW" =~ ^[0-9]+$ ]]; then
  echo "Error: version must be a positive integer (got '$NEW')." >&2
  exit 1
fi

mapfile -t files < <(grep -rlE '\?v=[0-9]+' --include='*.html' . || true)
if [[ ${#files[@]} -eq 0 ]]; then
  echo "No versioned assets found."
  exit 0
fi

sed -i -E "s/\?v=[0-9]+/?v=${NEW}/g" "${files[@]}"

echo "Set asset version to ?v=${NEW} in ${#files[@]} file(s):"
printf '  %s\n' "${files[@]}"
echo
echo "Next: redeploy these files. On first load after deploy a one-time"
echo "'Clear site data' (DevTools > Application) may be needed (Service Worker)."
