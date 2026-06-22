#!/usr/bin/env bash
# Compute and inject SRI hashes for CDN resources in HTML files.
# Run once after deployment (requires curl and openssl):
#   bash scripts/generate_sri.sh

set -euo pipefail

SRI() {
    local url="$1"
    printf "sha384-$(curl -sf "$url" | openssl dgst -sha384 -binary | openssl base64 -A)"
}

BOOTSTRAP_CSS_URL="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
BOOTSTRAP_JS_URL="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
ICONS_CSS_URL="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css"

echo "Computing SRI hashes..."
BOOTSTRAP_CSS_SRI=$(SRI "$BOOTSTRAP_CSS_URL")
echo "Bootstrap CSS: $BOOTSTRAP_CSS_SRI"

BOOTSTRAP_JS_SRI=$(SRI "$BOOTSTRAP_JS_URL")
echo "Bootstrap JS:  $BOOTSTRAP_JS_SRI"

ICONS_CSS_SRI=$(SRI "$ICONS_CSS_URL")
echo "Bootstrap Icons CSS: $ICONS_CSS_SRI"

echo ""
echo "Paste these integrity attributes into your HTML files:"
echo "Bootstrap CSS:   integrity=\"$BOOTSTRAP_CSS_SRI\" crossorigin=\"anonymous\""
echo "Bootstrap JS:    integrity=\"$BOOTSTRAP_JS_SRI\" crossorigin=\"anonymous\""
echo "Bootstrap Icons: integrity=\"$ICONS_CSS_SRI\" crossorigin=\"anonymous\""
