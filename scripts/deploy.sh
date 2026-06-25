#!/usr/bin/env bash
#
# Deploy the app to a shared host via rsync over SSH. Runs the asset-version
# consistency gate first so a deploy never ships a stale CSS/JS mix.
# Formalizes the manual checklist in DEPLOY_HOSTER_KZ.md.
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

# 2. Build the deployable file set from git — an ALLOWLIST of tracked files.
#    This inherently excludes every gitignored local artifact (database.sqlite,
#    logs/, cache/, .rate_limit/, local uploads, .env*), so a dev-machine deploy
#    can never publish a SQLite DB or secrets into the web root. Dev-only tracked
#    paths (tests, CI, scripts, docs) are filtered out too. Protective files like
#    uploads/.htaccess are tracked, so they DO ship.
mapfile -t FILES < <(
  git ls-files | grep -vE '^(tests/|\.github/|scripts/|frontend/|.*\.md$|phpunit\.xml|docker-compose\.yml|package\.json|package-lock\.json)' || true
)

if [[ -z "${DEPLOY_TARGET:-}" ]]; then
  cat <<'EOF'
DEPLOY_TARGET is not set — printing the deployable file set instead of syncing.
Set it (e.g. in .deploy.env):  DEPLOY_TARGET="user@host:~/www/<domain>"

Deployable files (tracked only):
EOF
  printf '  %s\n' "${FILES[@]}"
  echo
  echo "Plus vendor/ (build with 'composer install --no-dev' — PHPMailer is needed for email)."
  exit 0
fi

echo "Deploying ${#FILES[@]} tracked files to ${DEPLOY_TARGET} ${DRY:+(dry run)}…"
rsync -avz ${DRY} --files-from=<(printf '%s\n' "${FILES[@]}") ./ "${DEPLOY_TARGET}/"

# Production dependencies live in vendor/ (gitignored), so sync them separately.
# Build with `composer install --no-dev` first so no dev packages are shipped.
if [[ -d vendor ]]; then
  echo "Syncing vendor/ …"
  rsync -avz ${DRY} vendor/ "${DEPLOY_TARGET}/vendor/"
fi

echo
echo "Done. On the FIRST load after deploy, a one-time 'Clear site data'"
echo "(DevTools → Application) may be needed to drop the old Service Worker/cache."
