#!/usr/bin/env bash
#
# Single source of truth for the static asset cache-busting version (?v=N).
#
# All entry points reference CSS/JS with a `?v=N` query string. This covers
# both static HTML (api/index.html, login.html, admin/index.html, help/*,
# legal/*) AND the PHP-rendered pages (api/index.php, login.php, appeal.php,
# api/docs/index.php) — the main SPA shell is a .php, so HTML-only bumping left
# it drifting. Historically these drifted out of sync, so a deploy could serve a
# stale mix. This script rewrites EVERY `?v=N` across all TRACKED html/php pages
# to a single value — bump it once and every page refreshes consistently.
#
# File set = `git ls-files '*.html' '*.php'`: tracked files only, so gitignored
# vendor/ (Composer libs, some of which carry ?v= in examples) is never touched.
#
# Usage:
#   scripts/bump-assets.sh <integer>     # set all assets to ?v=<integer>
#   scripts/bump-assets.sh               # show current versions in use
#
set -euo pipefail
cd "$(dirname "$0")/.."

# Tracked html/php pages that may carry versioned asset refs.
pages() {
  git ls-files '*.html' '*.php'
}

show_current() {
  echo "Asset versions currently in use:"
  # `|| true`: with `set -o pipefail`, grep exiting non-zero on no matches
  # would otherwise abort the script instead of printing an empty summary.
  { pages | xargs grep -hoE '\?v=[0-9]+' 2>/dev/null || true; } | sort | uniq -c
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

mapfile -t files < <(pages | while IFS= read -r f; do grep -qE '\?v=[0-9]+' "$f" && printf '%s\n' "$f"; done)
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
