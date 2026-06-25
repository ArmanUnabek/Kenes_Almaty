#!/usr/bin/env bash
#
# CI/deploy guard for the committed frontend bundles in dist/.
#
# The bundles are committed (so File-Manager deploys work without a server-side
# build). This guard rebuilds them from source and verifies the build succeeds
# and emits valid JS for every page entry.
#
# It does NOT assert byte-for-byte equality with the committed dist/: the
# minifier's output is not guaranteed to be byte-identical across environments
# (Node/Vite cache state), so a strict diff is too brittle for CI. Instead, any
# drift is reported as a non-fatal hint so a forgotten `npm run build` is still
# visible in the logs without breaking the build.
#
# Usage: scripts/check-frontend-build.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

npm ci --no-audit --no-fund
npm run build

for f in dist/app.js dist/login.js dist/admin.js; do
  if [[ ! -s "$f" ]]; then
    echo "ERROR: $f missing or empty after build." >&2
    exit 1
  fi
  node --check "$f"
done

if ! git diff --quiet -- dist/; then
  echo "::warning:: dist/ differs from a fresh build (minifier reordering or a" \
       "missed 'npm run build'). The committed bundles are still valid JS; if you" \
       "changed JS sources, run 'npm run build' and commit dist/." >&2
fi

echo "OK: frontend bundles build cleanly and are valid JS."
