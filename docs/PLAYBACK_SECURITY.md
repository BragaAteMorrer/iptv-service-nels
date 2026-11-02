# Playback release gate — o2switch

Production playback is disabled **in PHP**, independently from the Apache `public_html` API restriction.

- `PlaybackController::session` returns HTTP 403 outside explicit local development, before it reads channels or contacts an upstream provider.
- `PlaybackController::relay` returns HTTP 403 outside explicit local development, before any ticket handling or outbound requests.
- Even in local development, a bearer session/device may request only channels from sources expressly granted in `user_source_access`. The legacy admin token is treated separately.
- A local developer must set `APP_ENV=dev` **and** `IPTV_ENABLE_LOCAL_RELAY=1`; never set these on public o2switch hosting.
- No new schema migrations are needed for this change. Keep the public web root access gate limited to `GET /api/health`.

Deployment checks from `~/nsatellite`:

```bash
git pull --ff-only origin feat/web-source-imports
php -l src/Controller/PlaybackController.php
php bin/console cache:clear --env=prod
php bin/console lint:container
php bin/console iptv:db:check
curl -i https://nsatellite.bragaatemorrer.fr/api/health
curl -i https://nsatellite.bragaatemorrer.fr/api/playback/relay
```

The last URL must be blocked (HTTP 403 or 404); no media stream should be delivered.

Remaining work before public API: functional integration tests covering two users/sources, revoked sessions and devices; review all streaming/VOD entrypoints; per-account administration; rate limiting for distributed deployment; complete relay design for a dedicated streaming service.
