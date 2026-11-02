#!/usr/bin/env bash
set -euo pipefail

# Run inside /home/<account>/nsatellite, after copying code from GitHub.
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PUBLIC_ROOT="$APP_ROOT/public_html"

if [[ ! -f "$APP_ROOT/composer.json" || ! -f "$APP_ROOT/public/index.php" ]]; then
  echo "Application incomplete at $APP_ROOT" >&2
  exit 1
fi
mkdir -p "$PUBLIC_ROOT"
# Do not delete provider-managed files such as .well-known.
rsync -a --exclude='index.php' --exclude='.htaccess' \
  "$APP_ROOT/public/" "$PUBLIC_ROOT/"
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/index.php" "$PUBLIC_ROOT/index.php"
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/.htaccess" "$PUBLIC_ROOT/.htaccess"
echo "public_html ready; deployment is NOT production-approved."
