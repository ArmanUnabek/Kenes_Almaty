#!/usr/bin/env bash
#
# Deploy the app to a shared host via rsync over SSH, excluding dev/test files.
# Runs the asset-version consistency gate first so a deploy never ships a stale
# CSS/JS mix. Formalizes the manual checklist in DEPLOY_HOSTER_KZ.md.
#
# Configure the target (env var or a gitignored .deploy.env file):
#   DEPLOY_TARGET="user@host:~/www/zhurnal.zhasylumit.kz"
#
# Usage:
#   scripts/deploy.sh --dry-run     # preview (rsync dry run, or file list if no target)
#   scripts/deploy.sh               # actually sync
#
set -euo pipefail
cd "$(dirname "$0")/.."

DRY=""
[[ "${1:-}" == "--dry-run" ]] && DRY="--dry-run"

# Optional local config (gitignored): may set DEPLOY_TARGET
[[ -f .deploy.env ]] && source .deploy.env

# 1. Gate: all HTML entry points must use a single asset version.
scripts/check-asset-versions.sh

# 2. Files that must never be shipped to production.
EXCLUDES=(
  --exclude '.git/'            --exclude '.github/'
  --exclude 'tests/'           --exclude 'phpunit.xml'
  --exclude '.phpunit.cache/'  --exclude 'scripts/'
  --exclude '*.md'             --exclude 'docker-compose.yml'
  --exclude 'node_modules/'    --exclude '.deploy.env'
  --exclude '.env'             --exclude '.env.local'
)

if [[ -z "${DEPLOY_TARGET:-}" ]]; then
  cat <<'EOF'
DEPLOY_TARGET is not set — printing the deployable file set instead of syncing.
Set it (e.g. in .deploy.env):  DEPLOY_TARGET="user@host:~/www/<domain>"

Deployable files:
EOF
  git ls-files \
    | grep -vE '^(tests/|\.github/|scripts/|.*\.md$|phpunit\.xml|docker-compose\.yml)' \
    | sed 's/^/  /'
  echo
  echo "Note: build vendor/ with 'composer install --no-dev' before a real deploy"
  echo "(PHPMailer is needed for email; dev packages are not)."
  exit 0
fi

echo "Deploying to ${DEPLOY_TARGET} ${DRY:+(dry run)}…"
rsync -avz ${DRY} "${EXCLUDES[@]}" ./ "${DEPLOY_TARGET}/"

echo
echo "Done. On the FIRST load after deploy, a one-time 'Clear site data'"
echo "(DevTools → Application) may be needed to drop the old Service Worker/cache."
