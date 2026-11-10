#!/usr/bin/env bash
# Read-only diagnostic for the NSatellite browser release on o2switch.
# Never prints passwords, tokens, or private environment variables.
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEST="$APP_ROOT/public_html"
BASE="https://nsatellite.bragaatemorrer.fr"

echo "=== NSatellite / navigateur PC ==="
echo "Git branch: $(git -C "$APP_ROOT" branch --show-current 2>/dev/null || echo inconnu)"
echo "Git commit: $(git -C "$APP_ROOT" rev-parse --short HEAD 2>/dev/null || echo inconnu)"
echo "Dossier web attendu: $DEST"
echo
missing=0
for path in home.html tv.html catalog.html series.html lineup.html player.html account.html assets/nsatellite-themes.css assets/nsatellite-theme.js assets/nsatellite-auth.js .htaccess index.php; do
  if [[ -f "$DEST/$path" ]]; then
    printf "OK      %s\n" "$path"
  else
    printf "ABSENT  %s\n" "$path"
    missing=1
  fi
done

if [[ -d "$DEST/assets" ]]; then
  mode="$(stat -c '%a' "$DEST/assets" 2>/dev/null || echo '?')"
  printf "Dossier assets : droits %s (attendu : 755)\n" "$mode"
  # Check other-user traverse bits; do not rely only on the owner's access.
  if [[ "$mode" != "755" ]]; then
    echo "ATTENTION : Apache peut retourner HTTP 403 sur les fichiers JS/CSS."
    missing=1
  fi
else
  echo "ABSENT  public_html/assets"
  missing=1
fi

if [[ -f "$DEST/.htaccess" ]] && grep -q 'DirectoryIndex home.html index.html' "$DEST/.htaccess"; then
  echo "OK      DirectoryIndex : home.html prioritaire"
else
  echo "ATTENTION : la priorité de la page d'accueil n'est pas vérifiée"
  missing=1
fi

if command -v curl >/dev/null 2>&1; then
  echo
  echo "=== Réponses HTTP anonymes (sans identifiants) ==="
  for path in / /home.html /tv.html /assets/nsatellite-themes.css /assets/nsatellite-theme.js /assets/nsatellite-auth.js /api/identity/browser/session /api/lineup /api/playback/relay; do
    code="$(curl --connect-timeout 5 --max-time 12 -sS -o /dev/null -w '%{http_code}' "$BASE$path" 2>/dev/null || true)"
    printf "%-37s %s\n" "$path" "${code:-réseau indisponible}"
    case "$path" in
      /home.html|/tv.html|/assets/nsatellite-themes.css|/assets/nsatellite-theme.js|/assets/nsatellite-auth.js)
        if [[ "$code" != 200 ]]; then
          echo "ERREUR : ressource essentielle HTTP $code ($path)."
          echo "Vérifie les permissions du dossier public_html/assets, puis le DocumentRoot."
          missing=1
        fi
      ;;
    esac
  done
  echo "Attendu : pages /home.html et /tv.html => 200."
  echo "Session navigateur anonyme => 401 si l'API identité est activée, sinon 404."
  echo "API lineup anonyme => 401 si activée, 404 si verrouillée."
  echo "Relais PHP /api/playback/relay => 403 ou 404, jamais 200."
  # Inspect only the public HTML fingerprint, without printing user or provider data.
  root_html="$(curl --connect-timeout 5 --max-time 12 -fsS "$BASE/" 2>/dev/null || true)"
  if [[ "$root_html" == *"NSatellite — Accueil"* ]]; then
    echo "ACCUEIL ACTIF : le domaine sert la nouvelle interface navigateur."
  elif [[ "$root_html" == *"Nels TV — Connexion"* ]]; then
    echo "ANCIEN ACCUEIL : le domaine sert toujours la page de préproduction."
    echo "Vérifie le DocumentRoot, le fichier .htaccess et le cache."
  else
    echo "Accueil du domaine non identifié : vérifie manuellement le HTML rendu."
  fi
else
  echo "curl absent : vérifications réseau ignorées."
fi

echo
if (( missing != 0 )); then
  echo "L'interface PC n'est pas entièrement installée dans le dossier attendu."
  echo "Lis docs/O2SWITCH_VIEWER_RELEASE.md avant de lancer install-viewer.sh."
  exit 1
fi
echo "Les fichiers requis sont présents ET les ressources publiques répondent HTTP 200."
echo "Le fonctionnement de la connexion et du catalogue nécessite toujours des tests authentifiés."
