#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PUBLIC_ROOT="$APP_ROOT/public_html"
if [[ ! -f "$APP_ROOT/composer.json" || ! -f "$APP_ROOT/vendor/autoload.php" ]]; then
  echo "Dependencies missing at $APP_ROOT" >&2
  exit 1
fi
mkdir -p "$PUBLIC_ROOT"
# This script intentionally maintains the limited staging page, not the PC/TV portal.
# Do not accidentally replace the active browser release with a staging install.
if [[ -f "$PUBLIC_ROOT/home.html" ]]; then
  echo "Interface navigateur détectée : ne réinstalle pas la page de préproduction." >&2
  echo "Utilise plutôt : NSATELLITE_INSTALL_VIEWER_UI=1 bash deploy/o2switch/install-viewer.sh" >&2
  exit 2
fi
# Only install the staging landing page, a health-only front controller and Apache restrictions.
# Preserve provider-managed .well-known (TLS challenges), and leave existing files untouched.
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/index.html" "$PUBLIC_ROOT/index.html"
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/index.php" "$PUBLIC_ROOT/index.php"
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/.htaccess" "$PUBLIC_ROOT/.htaccess"
echo "Safe staging page and deny-by-default API gateway installed in public_html."
echo "Identity endpoints require NELSTV_ENABLE_IDENTITY_API=1; viewer routes additionally require NELSTV_ENABLE_VIEWER_API=1."
echo "Public video playback remains off unless IPTV_ENABLE_DIRECT_HLS=1 and a public HLS host is explicitly approved."
echo "Pour installer la vraie interface PC/TV, utilise deploy/o2switch/install-viewer.sh (avec NSATELLITE_INSTALL_VIEWER_UI=1)."
