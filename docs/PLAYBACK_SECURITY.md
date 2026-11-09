# NSatellite playback modes — o2switch release gate

Production HLS **relay in PHP is disabled**. The previous experimental development
relay remains accessible only with `APP_ENV=dev` and
`IPTV_ENABLE_LOCAL_RELAY=1`. Do not enable it on public o2switch hosting.

## Production direct-HLS mode (optional, off by default)

NSatellite can now return a **direct HTTPS HLS URL** to an authenticated account or
paired device, without sending the video bytes through o2switch. This is
appropriate **only for deliberately public/credential-free streams** that allow
browser-origin playback via CORS. It is **not** an Xtream, credentialed M3U,
MPEG-TS, DRM or provider-restriction bypass.

All of the following are required:

- `APP_ENV=prod` and `APP_DEBUG=0`.
- `IPTV_ENABLE_DIRECT_HLS=1` set in the private server environment.
- The exact source hostname included in BOTH `IPTV_PUBLIC_HLS_HOSTS` and
  `IPTV_TRUSTED_HOSTS` (comma-separated, no wildcard).
- M3U source, authorized by `user_source_access` for the authenticated user.
  The legacy administrator token is not sufficient for production playback.
- URL is HTTPS on default port 443, ends in `.m3u8`, has no username,
  password, query string or fragment, and is not in a known Xtream
  credential-bearing path format.
- Valid public DNS only, as checked by `TrustedProviderEndpoint`.
- The provider explicitly permits the playlist, variant playlists and segments
  to be fetched cross-origin in a browser.

**Important:** URLs can contain credentials in arbitrary path components even
without a query string. Putting a host in `IPTV_PUBLIC_HLS_HOSTS` is an
administrator assertion that its direct playlist URLs are safe to expose to
the user/browser. Do not add subscription-only Xtream servers or secret paths.

Example private environment, with nonfunctional placeholder host:

```dotenv
APP_ENV=prod
APP_DEBUG=0
IPTV_ENABLE_DIRECT_HLS=1
IPTV_PUBLIC_HLS_HOSTS=cdn.example.org
IPTV_TRUSTED_HOSTS=cdn.example.org
```

Browser behavior: `POST /api/playback/channel/{id}/session` returns
`{"mode":"direct-hls","url":"https://.../channel.m3u8","expires_in":0}`
only for an allowed source. The browser then contacts the provider directly.
This **does not remove CORS requirements** for hls.js. Native HLS browsers may
have different codec/CORS capabilities. No provider headers or credentials are
added to the browser request.

All other production playback requests fail closed, and
`GET /api/playback/relay` remains HTTP 403. In particular, Xtream URLs,
MPEG-TS-only streams, encrypted HLS, streams requiring custom HTTP headers and
CORS-incompatible HLS need a dedicated, authorized media gateway or a
provider-approved alternative. PHP shared hosting is not a general-purpose
streaming proxy.

## Hosting gateway is a second, independent gate

The repository's `deploy/o2switch/public_html/.htaccess` and
`index.php` implement a separate HTTP allowlist. The backend flag alone
**does not expose the session endpoint or deploy HTML assets** on o2switch.
Do not alter the public API restrictions without reviewing the corresponding
gateway release and testing both authenticated access and anonymous denial.

## Verification

- `php tests/direct_hls_policy_smoke.php` — deterministic policy fixtures,
  no network, provider, database or real credentials.
- `php -l src/Controller/PlaybackController.php`
- `php -l src/Security/DirectHlsPolicy.php`
- `APP_ENV=prod` with `IPTV_ENABLE_DIRECT_HLS=0` must reject a session.
- With opt-in, unauthorized accounts and unassigned sources must fail.
- Approved public M3U HLS requires browser CORS, and must work without
  `GET /api/playback/relay`.
- Confirm `/api/playback/relay` still returns 403 on production.
- Check no playlist URL, provider credentials, session tokens or query tickets
  appear in web logs, browser error messages or support dumps.

No repository commit directly updates the live o2switch instance; only deploy
with a rollback plan after staging verification and provider authorization.
