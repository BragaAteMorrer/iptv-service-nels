#!/usr/bin/env bash
# Read-only diagnostic. Never submit real credentials, provider URLs or cookies.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WEB="$ROOT/public_html"
BASE="https://nsatellite.bragaatemorrer.fr"
failed=0
echo "=== NSatellite Mon espace : diagnostic 404 ==="
echo "Git : $(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo inconnu)"
for name in .htaccess index.php; do
  if [[ ! -f "$WEB/$name" ]]; then
    echo "ABSENT public_html/$name";failed=1
  elif cmp -s "$ROOT/deploy/o2switch/public_html/$name" "$WEB/$name"; then
    echo "OK public_html/$name synchronisé avec Git"
  else
    echo "DÉCALAGE public_html/$name : ancienne passerelle ou personnalisation locale"
    failed=1
  fi
done

echo
echo "=== Flags lus par PHP CLI (pas de secret affiché) ==="
if [[ -f "$ROOT/vendor/autoload.php" ]]; then
  ROOT="$ROOT" php -r '
  require getenv("ROOT")."/vendor/autoload.php";
  \IptvService\Bootstrap\Environment::load(getenv("ROOT"));
  $flags=[];
  foreach (["NELSTV_ENABLE_IDENTITY_API","NELSTV_ENABLE_VIEWER_API"] as $key) {
    $flags[$key]=(($_SERVER[$key]??getenv($key)??"0")==="1");
    echo $key."=".($flags[$key]?"1":"0").PHP_EOL;
  }
  echo "POST /api/account/sources autorisé par le gate CLI : ".
    (\IptvService\Security\PublicViewerRouteGate::allows("POST","/api/account/sources",
      $flags["NELSTV_ENABLE_IDENTITY_API"],$flags["NELSTV_ENABLE_VIEWER_API"],false)?"OUI":"NON").PHP_EOL;
  '
else
  echo "ERREUR : dépendances PHP non installées";failed=1
fi

echo
echo "=== Symfony ==="
if [[ -f "$ROOT/bin/console" ]]; then
  routes="$(cd "$ROOT" && APP_ENV=prod APP_DEBUG=0 php bin/console debug:router 2>/dev/null || true)"
  if [[ "$routes" == *"/api/account/sources"* ]]; then
    echo "OK : route Symfony /api/account/sources détectée"
  else
    echo "ERREUR : route Symfony absente ou cache non lisible";failed=1
  fi
fi

echo
echo "=== HTTP anonyme, sans cookie ni fournisseur ==="
for method in GET POST; do
  if [[ "$method" == POST ]]; then
    code="$(curl --connect-timeout 5 --max-time 12 -sS -o /dev/null -w '%{http_code}' \
      -X POST -H 'Content-Type: application/json' --data '{}' "$BASE/api/account/sources" 2>/dev/null || true)"
  else
    code="$(curl --connect-timeout 5 --max-time 12 -sS -o /dev/null -w '%{http_code}' \
      "$BASE/api/account/sources" 2>/dev/null || true)"
  fi
  echo "$method /api/account/sources : HTTP ${code:-réseau indisponible}"
  case "$code" in
    401) echo "OK : la route fonctionne et demande une authentification" ;;
    404) echo "BLOQUÉ : route refusée par le gate Apache/PHP ou absente dans Symfony";failed=1 ;;
    403) echo "REFUS : vérifier WAF, Origin, HTTPS et règles Apache";failed=1 ;;
    *) echo "INATTENDU : voir les journaux, ne jamais publier de secrets";failed=1 ;;
  esac
done
echo
if (( failed != 0 )); then
  echo "Une correction de configuration/déploiement est nécessaire."
  exit 1
fi
echo "Les routes sont accessibles. Tu peux ensuite tester avec ton compte."
