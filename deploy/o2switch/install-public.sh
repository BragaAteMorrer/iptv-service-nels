#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PUBLIC_ROOT="$APP_ROOT/public_html"
if [[ ! -f "$APP_ROOT/composer.json" || ! -f "$APP_ROOT/vendor/autoload.php" ]]; then
  echo "Dependencies missing at $APP_ROOT" >&2
  exit 1
fi
mkdir -p "$PUBLIC_ROOT"
# Only install the staging landing page, a health-only front controller and Apache restrictions.
# Preserve provider-managed .well-known (TLS challenges), and leave existing files untouched.
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/index.html" "$PUBLIC_ROOT/index.html"
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/index.php" "$PUBLIC_ROOT/index.php"
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/.htaccess" "$PUBLIC_ROOT/.htaccess"
echo "Safe staging page and opt-in identity API gateway installed in public_html."
echo "Identity API remains disabled unless NELSTV_ENABLE_IDENTITY_API=1; all other APIs remain blocked."
