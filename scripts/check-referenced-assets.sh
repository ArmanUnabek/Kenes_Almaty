#!/usr/bin/env bash
#
# CI/deploy guard: every LOCAL static asset referenced by the HTML entry points
# must actually exist in the repo.
#
# Why: the app is served under a strict no-CDN CSP, so Bootstrap/Chart.js/Inter
# and every script/style are loaded from same-origin paths (/assets/..., /dist/...,
# /js/..., /api/js/...). If such a file is referenced but not committed (e.g. it
# sits in a gitignored dir), the browser 404s it and whole features silently die
# — exactly how the vendored Bootstrap bundle went missing once. A `php -l` /
# `node --check` pass can't catch that; this guard does.
#
# It checks src="/…" and href="/…" references with a static-asset extension,
# strips the ?v= cache-buster, and resolves them against the repo root. Dynamic
# (.php) endpoints and absolute http(s):// URLs are ignored.
#
# Usage: scripts/check-referenced-assets.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

# Entry points that reference static assets (every user-facing page under the
# no-CDN CSP). Keep this list complete — an unlisted page's missing asset would
# go unnoticed (that's how the API-docs Swagger bundle was overlooked).
PAGES=(
  api/index.php
  admin/index.html
  login.html
  login.php
  appeal.php
  appeal_status.php
  api/docs/index.php
)

# Extensions we treat as static files that must exist on disk.
STATIC_EXT='css|js|mjs|woff2?|ttf|otf|eot|png|jpe?g|gif|svg|ico|webp|json|webmanifest|map'

missing=0
checked=0

for page in "${PAGES[@]}"; do
  [ -f "$page" ] || continue
  # Pull the URL out of every src="…" / href="…" attribute.
  while IFS= read -r url; do
    # Only root-relative local paths ("/something").
    case "$url" in
      /*) ;;
      *) continue ;;
    esac
    # Strip query string / fragment (the ?v= cache-buster).
    path="${url%%[?#]*}"
    # Skip directory links (e.g. /admin/, /help/, /api/) — those resolve to an
    # index handler, not a single static file.
    case "$path" in
      */) continue ;;
    esac
    # Only static-asset extensions (skips /api/index.php, /auth.php, etc.).
    ext="${path##*.}"
    if ! printf '%s' "$ext" | grep -qiE "^(${STATIC_EXT})$"; then
      continue
    fi
    file=".${path}"   # repo-root relative
    checked=$((checked + 1))
    if [ ! -f "$file" ]; then
      echo "::error file=${page}::referenced asset missing on disk: ${url} -> ${file}" >&2
      missing=1
    fi
  done < <(grep -oE '(src|href)="[^"]+"' "$page" | sed -E 's/^(src|href)="//; s/"$//')
done

if [ "$missing" -ne 0 ]; then
  echo "FAIL: one or more referenced static assets are missing from the repo." >&2
  exit 1
fi

echo "OK: all ${checked} referenced static assets exist."
