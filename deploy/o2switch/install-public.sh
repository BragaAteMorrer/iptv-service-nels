#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PUBLIC_ROOT="$APP_ROOT/public_html"
if [[ ! -f "$APP_ROOT/composer.json" || ! -f "$APP_ROOT/vendor/autoload.php" ]]; then
  echo "Dependencies missing at $APP_ROOT" >&2
  exit 1
fi
mkdir -p "$PUBLIC_ROOT"
# Only install a public staging page. Do not copy admin pages, API routes or relays.
# Preserve provider-managed .well-known (TLS challenges).
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/index.html" "$PUBLIC_ROOT/index.html"
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/.htaccess" "$PUBLIC_ROOT/.htaccess"
echo "Safe Nels TV staging landing page installed in public_html."
echo "Symfony/API activation is intentionally blocked pending security review."
