# NSatellite o2switch viewer rollout (opt-in, no PHP media relay)

The application files live outside the web root in `~/nsatellite`. Only
`~/nsatellite/public_html` is exposed by Apache. This release creates a
**minimal viewer-only web surface**; it must not publish the admin/import
endpoints or the experimental PHP HLS/MP4 relay.

## Two independent opt-in gates

The public gateway permits only exact, account-scoped API routes when both
flags are present in the private `.env.local` (or equivalent environment):

```dotenv
APP_ENV=prod
APP_DEBUG=0
NELSTV_ENABLE_IDENTITY_API=1
NELSTV_ENABLE_VIEWER_API=1
```

Viewer API includes read-only catalog and EPG, lineup, indexed-series metadata,
and the authenticated account-preferences PATCH endpoint. Existing identity
and device endpoints remain protected by the identity flag.

**Video playback is separately disabled** until:

```dotenv
IPTV_ENABLE_DIRECT_HLS=1
IPTV_PUBLIC_HLS_HOSTS=cdn.example.org
IPTV_TRUSTED_HOSTS=cdn.example.org
```

The example host is intentionally nonfunctional. Set the actual exact public
hostname only after checking the content provider permits credential-free
HTTPS HLS and browser CORS. A valid authenticated account must also have access
to the source. **Never use this option for credential-bearing Xtream/M3U
streams, TS-only streams or private playback URLs.**

The production gateway routes POST
`/api/playback/channel/{id}/session` only in direct-HLS mode. Neither
`/api/playback/relay` nor VOD `/api/v1/vod/range` is exposed.

## Preparation and installation

Back up the current deployment and `public_html/.htaccess`, check Git
status and the current branch before updating. Never overwrite local changes
with `git reset --hard`. Assuming the working copy is clean on `main`:

```bash
cd ~/nsatellite
git status --short
git branch --show-current
git pull --ff-only origin main
php -l src/Security/DirectHlsPolicy.php
php -l src/Controller/PlaybackController.php
php -l deploy/o2switch/public_html/index.php
php tests/direct_hls_policy_smoke.php
php tests/o2switch_viewer_route_gate_smoke.php
php tests/browser_session_security_smoke.php
php bin/console cache:clear --env=prod
php bin/console iptv:db:check
```

## Why you might still see the old narrow login page

If `https://nsatellite.bragaatemorrer.fr/` shows only **"Nels TV —
Connexion établie / Mes appareils / Associer une Android TV"**, you are
looking at the **staging `public_html/index.html`**, not the new browser
application. Merging a GitHub PR (even into `main`) does not update
`~/nsatellite` or copy the `public/*.html` files to `public_html`.

The actual PC browser pages are:
- `/home.html` — NSatellite home and navigation
- `/tv.html` — live TV decoder with side channel list, grid/EPG, keyboard and mouse
- `/catalog.html` — browse the catalog
- `/series.html` — series browsing
- `/lineup.html` — organization of personal channel preferences

**To publish the PC pages**, use these commands over SSH on o2switch,
after confirming the working tree is clean:

```bash
cd ~/nsatellite
git status --short
git branch --show-current
git fetch origin
git switch main
git pull --ff-only origin main

# Diagnostic before installation: old deployments will show missing pages.
bash deploy/o2switch/check-browser.sh || true

# Explicit opt-in, private backup of replaced web files, no API activation.
NSATELLITE_INSTALL_VIEWER_UI=1 bash deploy/o2switch/install-viewer.sh

# Verify local files and HTTP responses from the live domain.
bash deploy/o2switch/check-browser.sh
```

Do not run `git reset --hard` or overwrite local changes to force a
branch switch. If `git status --short` shows changes, resolve them
first; do not run the deployment commands blindly.

The viewer installer backs up web files it replaces into private
`~/nsatellite/var/deploy-backups/browser-...` directories, copies only
known HTML pages, the themes and the browser-auth JS asset, and replaces the restrictively
configured `.htaccess` and PHP front controller. It **preserves
`.well-known` and pre-existing unlisted files**, including any existing
customizations outside its explicit file list. It also refreshes the
fallback `/index.html` page with a link to the PC interface, but that
link only appears after the target `/home.html` is actually reachable.

With `DirectoryIndex home.html index.html`, the site's root `/`
will display the new NSatellite home after installation. If the root
still shows the staging login page, open `/home.html` explicitly, then
check the vhost **DocumentRoot**, CDN/browser cache, and the diagnostics
above. The installer cannot guarantee that the domain points at
`~/nsatellite/public_html`.

### API activation is a separate operation

Static pages can be installed safely **without enabling playback**.
To use authenticated listings and the catalog, the operator must
verify the private environment has `NELSTV_ENABLE_IDENTITY_API=1`
and then explicitly enable `NELSTV_ENABLE_VIEWER_API=1`.
Keep `IPTV_ENABLE_DIRECT_HLS=0` until an authorized, credential-free
HTTPS HLS source with suitable CORS is verified. Never post your
private environment file, passwords or provider URLs to support chat.

Even with the viewer flag enabled, an account must have explicit
`user_source_access` grants. This does not implement playback for
subscription Xtream or raw MPEG-TS streams. Test with a non-sensitive
source and keep a rollback plan.

## Acceptance checks

1. `GET /`, `/home.html`, `/tv.html` and `/assets/nsatellite-theme.js`
   should load as static assets. Confirm the CSS and JS paths in DevTools.
2. Anonymous `GET /api/lineup` must respond HTTP 401 with the viewer
   flag enabled (or HTTP 404 while disabled).
3. Anonymous `POST /api/playback/channel/1/session` with
   `Content-Type: application/json` and body `{}` must be denied.
4. `GET /api/playback/relay`, `GET /api/sources` and
   `GET /api/v1/vod/range` must remain blocked by Apache/PHP.
5. Login, device pairing, authorized lineup, account favorites and EPG
   must work. One user must never see another user's source.
6. With an explicitly approved, CORS-enabled public HLS stream, playback
   must issue a direct HTTPS provider request **from the browser**.
   The PHP server must not download video segments. Test Chrome/Edge/Firefox,
   reconnect and fullscreen. CORS failures cannot be solved by frontend code.
7. Verify `APP_DEBUG=0`, that no token or secret URL is logged or shared,
   and that PHP/cPanel resource use remains normal.

The page and route rollout does **not** make subscription Xtream, TS streams,
protected VOD, arbitrary HLS playlists, or unauthenticated viewing usable.
Those require a separately designed authorized media service.

## Rollback

Disable `NELSTV_ENABLE_VIEWER_API` and `IPTV_ENABLE_DIRECT_HLS` in private
configuration first. To restore the staging homepage, restore the backed-up
`.htaccess`/web files or temporarily rename `public_html/home.html` so the
preserved `index.html` becomes the directory index again. Leave the database
and provider credentials untouched. This GitHub change does not itself deploy
to o2switch.


## Persistently signed-in browser (current release)

The web interface now shares a 7-day browser session across `/home.html`,
`/tv.html`, `/catalog.html`, `/series.html`, `/lineup.html`,
`/devices.html` and the preserved staging login. The login stores a
Secure, HttpOnly, SameSite=Lax host-only cookie; no bearer token is stored in
JavaScript/localStorage. Logout revokes the server session.

The viewer installer now deploys `/assets/nsatellite-auth.js`. The new
browser-identity routes require the identity API flag, and mutating cookie
requests require the same-origin CSRF header. After deployment test login,
page reload, cross-page navigation, a browser restart, and logout. See
[BROWSER_SESSION.md](BROWSER_SESSION.md) for the checklist. A GitHub merge
alone still does not update o2switch.


## Diagnostic HTTP 403 sur les fichiers JavaScript/CSS

Si `/home.html` renvoie HTTP 200 mais que `/assets/nsatellite-auth.js`
`/assets/nsatellite-theme.js` ou `/assets/nsatellite-themes.css`
renvoient HTTP 403, la page peut afficher
`NSatelliteAuth is not defined` et la connexion devient impossible.

Cause corrigée : le script d'installation utilisait `umask 077` (utile pour
les sauvegardes privées), ce qui pouvait créer `public_html/assets` avec
les droits **700**. Apache ne pouvait pas traverser ce dossier.

Le nouvel installateur applique explicitement **755 uniquement sur le
répertoire public `public_html/assets`** et conserve les sauvegardes
privées en 700. Un test CI vérifie cette propriété avant fusion.

Pour corriger une installation déjà affectée :

```bash
cd ~/nsatellite
chmod 755 public_html/assets
curl -sSI https://nsatellite.bragaatemorrer.fr/assets/nsatellite-auth.js
bash deploy/o2switch/check-browser.sh
```

Le diagnostic échoue désormais si une ressource HTML/JS/CSS essentielle ne
répond pas HTTP 200, même lorsque le fichier est présent sur le disque.
Ne rends pas publics `~/nsatellite/var`, la configuration ni les secrets.
