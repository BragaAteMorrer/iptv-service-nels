#!/usr/bin/env bash
# Opt-in installation of the NSatellite *viewer* UI on o2switch.
# This script never deploys admin API routes or provider credentials.
set -euo pipefail
if [[ "${NSATELLITE_INSTALL_VIEWER_UI:-0}" != "1" ]]; then
  echo "Refusing: set NSATELLITE_INSTALL_VIEWER_UI=1 to copy viewer pages." >&2
  exit 2
fi

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE="$APP_ROOT/public"
DEST="$APP_ROOT/public_html"
if [[ ! -f "$APP_ROOT/vendor/autoload.php" || ! -f "$DEST/index.php" ]]; then
  echo "Missing dependencies or staged public_html entrypoint. Install and check staging first." >&2
  exit 1
fi

# Install only known static pages needed for personal viewing.
# Do NOT copy admin.html, router.php, index.php or any source/config file from public.
pages=(home.html tv.html catalog.html series.html lineup.html player.html devices.html)
for page in "${pages[@]}"; do
  test -f "$SOURCE/$page"
done
test -f "$SOURCE/assets/nsatellite-themes.css"
test -f "$SOURCE/assets/nsatellite-theme.js"

mkdir -p "$DEST/assets"
for page in "${pages[@]}"; do
  install -m 0644 "$SOURCE/$page" "$DEST/$page"
done
install -m 0644 "$SOURCE/assets/nsatellite-themes.css" "$DEST/assets/nsatellite-themes.css"
install -m 0644 "$SOURCE/assets/nsatellite-theme.js" "$DEST/assets/nsatellite-theme.js"

# Deploy the restrictive gateway as part of the same opt-in viewer release.
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/.htaccess" "$DEST/.htaccess"
install -m 0644 "$APP_ROOT/deploy/o2switch/public_html/index.php" "$DEST/index.php"

echo "Viewer pages installed. Existing index.html and .well-known preserved."
echo "APIs remain gated by NELSTV_ENABLE_IDENTITY_API and NELSTV_ENABLE_VIEWER_API."
echo "Direct HLS requires its separate server opt-in; no relay is enabled."
