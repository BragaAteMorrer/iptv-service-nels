#!/usr/bin/env bash
# Regression test for the o2switch 403 outage caused by umask 077.
# Runs in a temporary fake DocumentRoot; no network or real deployment.
set -euo pipefail
ROOT="$(mktemp -d)"
trap 'rm -rf "$ROOT"' EXIT
mkdir -p "$ROOT/deploy/o2switch/public_html" "$ROOT/public/assets" "$ROOT/public_html/assets" "$ROOT/vendor"
cp deploy/o2switch/install-viewer.sh "$ROOT/deploy/o2switch/install-viewer.sh"
cp deploy/o2switch/public_html/{index.html,index.php,.htaccess} "$ROOT/deploy/o2switch/public_html/"
for page in home.html tv.html catalog.html series.html lineup.html player.html devices.html account.html; do
  cp "public/$page" "$ROOT/public/$page"
done
for asset in nsatellite-themes.css nsatellite-theme.js nsatellite-auth.js; do
  cp "public/assets/$asset" "$ROOT/public/assets/$asset"
done
touch "$ROOT/vendor/autoload.php"
chmod 700 "$ROOT/public_html/assets"

NSATELLITE_INSTALL_VIEWER_UI=1 bash "$ROOT/deploy/o2switch/install-viewer.sh" >/dev/null
mode="$(stat -c %a "$ROOT/public_html/assets")"
[[ "$mode" == 755 ]] || { echo "FAIL: public assets mode $mode (expected 755)" >&2; exit 1; }
for asset in nsatellite-themes.css nsatellite-theme.js nsatellite-auth.js; do
  [[ -r "$ROOT/public_html/assets/$asset" ]] || { echo "FAIL: missing $asset" >&2; exit 1; }
done
backup_count="$(find "$ROOT/var/deploy-backups" -mindepth 1 -maxdepth 1 -type d | wc -l)"
[[ "$backup_count" -eq 1 ]] || { echo "FAIL: private deploy backup absent" >&2; exit 1; }
backup="$(find "$ROOT/var/deploy-backups" -mindepth 1 -maxdepth 1 -type d -print -quit)"
[[ "$(stat -c %a "$backup")" == 700 ]] || { echo "FAIL: backup directory is not private" >&2; exit 1; }
echo "PASS: public assets mode 755, scripts published, private backup mode 700"
