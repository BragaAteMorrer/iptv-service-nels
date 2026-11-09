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
php bin/console cache:clear --env=prod
php bin/console iptv:db:check
```

The installer requires an **explicit one-time approval**. It copies only
known viewer pages (`home`, `tv`, `catalog`, `series`, `lineup`, `player`,
`devices`) and the shared theme assets. It also installs the hardened
gateway. It does not copy `admin.html`, PHP source, secrets or migrations,
and it preserves `index.html` and `.well-known`.

```bash
NSATELLITE_INSTALL_VIEWER_UI=1 bash deploy/o2switch/install-viewer.sh
```

When `home.html` is present, `DirectoryIndex home.html index.html`
makes it the default landing page. Until the viewer flags are enabled,
API requests fail closed even if static pages are installed. Consider a
staging domain and manual acceptance test before enabling the flags publicly.

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
