#!/usr/bin/env bash
#
# CI/deploy guard: fail if the committed frontend bundles in dist/ are stale
# relative to their sources (frontend/*.entry.js + api/js/*.js + admin/* + …).
#
# The bundles are committed (so File-Manager deploys work without a server-side
# build), which means every JS change must be followed by `npm run build` and a
# commit of the regenerated dist/. This guard rebuilds and fails on any diff.
#
# Usage: scripts/check-frontend-build.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

npm ci --no-audit --no-fund
npm run build

if ! git diff --quiet -- dist/; then
  echo "ERROR: dist/ bundles are stale — run 'npm run build' and commit dist/." >&2
  git --no-pager diff --stat -- dist/ >&2
  exit 1
fi

echo "OK: committed frontend bundles match their sources."
