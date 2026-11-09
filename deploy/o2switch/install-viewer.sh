#!/usr/bin/env bash
# Publish the NSatellite PC/TV browser pages into o2switch's document root.
# Explicit opt-in only; does not enable identity, viewer API or video playback.
set -euo pipefail
if [[ "${NSATELLITE_INSTALL_VIEWER_UI:-0}" != "1" ]]; then
  echo "Refus : utilisation explicite requise (NSATELLITE_INSTALL_VIEWER_UI=1)." >&2
  exit 2
fi

umask 077
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SOURCE="$APP_ROOT/public"
DEPLOY="$APP_ROOT/deploy/o2switch/public_html"
DEST="$APP_ROOT/public_html"

if [[ ! -f "$APP_ROOT/vendor/autoload.php" || ! -d "$DEST" ]]; then
  echo "Dépendances absentes ou public_html inexistant : vérifie d'abord l'installation." >&2
  exit 1
fi
if [[ -L "$DEST" ]]; then
  echo "public_html est un lien symbolique : vérifie manuellement le DocumentRoot avant installation." >&2
  exit 1
fi

pages=(home.html tv.html catalog.html series.html lineup.html player.html devices.html)
assets=(nsatellite-themes.css nsatellite-theme.js nsatellite-auth.js)
for page in "${pages[@]}"; do
  [[ -f "$SOURCE/$page" ]] || { echo "Fichier manquant : public/$page" >&2; exit 1; }
done
for asset in "${assets[@]}"; do
  [[ -f "$SOURCE/assets/$asset" ]] || { echo "Asset manquant : public/assets/$asset" >&2; exit 1; }
done
for config in index.html index.php .htaccess; do
  [[ -f "$DEPLOY/$config" ]] || { echo "Configuration manquante : deploy/o2switch/public_html/$config" >&2; exit 1; }
done
php -l "$DEPLOY/index.php" >/dev/null
grep -q 'DirectoryIndex home.html index.html' "$DEPLOY/.htaccess" || {
  echo "Le serveur n'est pas configuré pour donner la priorité à home.html." >&2
  exit 1
}

# Snapshot every file potentially overwritten, outside public_html.
STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"
BACKUP="$APP_ROOT/var/deploy-backups/browser-$STAMP"
mkdir -p "$BACKUP/assets"
chmod 700 "$BACKUP" "$BACKUP/assets"
for page in "${pages[@]}" index.html index.php .htaccess; do
  if [[ -f "$DEST/$page" ]]; then cp -p "$DEST/$page" "$BACKUP/$page"; fi
done
for asset in "${assets[@]}"; do
  if [[ -f "$DEST/assets/$asset" ]]; then cp -p "$DEST/assets/$asset" "$BACKUP/assets/$asset"; fi
done

# Copy an explicit allowlist, never the entire public/ tree or admin tools.
mkdir -p "$DEST/assets"
for page in "${pages[@]}"; do
  install -m 0644 "$SOURCE/$page" "$DEST/$page"
done
for asset in "${assets[@]}"; do
  install -m 0644 "$SOURCE/assets/$asset" "$DEST/assets/$asset"
done
# Keep the old login accessible at /index.html, but add browser launch links.
install -m 0644 "$DEPLOY/index.html" "$DEST/index.html"
install -m 0644 "$DEPLOY/.htaccess" "$DEST/.htaccess"
install -m 0644 "$DEPLOY/index.php" "$DEST/index.php"

# Verify local copies (not proof that the vhost is pointed at this directory).
for page in "${pages[@]}"; do cmp -s "$SOURCE/$page" "$DEST/$page" || exit 1; done
for asset in "${assets[@]}"; do cmp -s "$SOURCE/assets/$asset" "$DEST/assets/$asset" || exit 1; done
cmp -s "$DEPLOY/.htaccess" "$DEST/.htaccess"
cmp -s "$DEPLOY/index.php" "$DEST/index.php"
cmp -s "$DEPLOY/index.html" "$DEST/index.html"

echo
echo "NSatellite Web installé dans : $DEST"
echo "Sauvegarde des fichiers remplacés : $BACKUP"
echo "Code Git courant : $(git -C "$APP_ROOT" rev-parse --short HEAD 2>/dev/null || echo inconnu)"
echo "À tester : https://nsatellite.bragaatemorrer.fr/home.html"
echo "Puis : https://nsatellite.bragaatemorrer.fr/tv.html"
echo "Les API restent verrouillées tant que leurs options privées ne sont pas activées."
echo "Vérifie aussi que le DocumentRoot du domaine pointe bien vers : $DEST"
