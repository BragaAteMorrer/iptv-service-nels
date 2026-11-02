# o2switch staged authentication API

The public site is **not** opening authentication automatically.

The Apache entrypoint routes selected identity URLs to Symfony, but the PHP front controller checks `NELSTV_ENABLE_IDENTITY_API` and returns 404 unless it equals `1`. Keep it **unset or 0** until integration tests with an isolated MariaDB database and a release review are complete.

Allowlisted only after opt-in:
- POST `/api/identity/login`, `/api/identity/logout`
- POST `/api/devices/pair/start`, `/api/devices/pair/approve`, `/api/devices/pair/status`
- GET `/api/devices`
- DELETE `/api/devices/{positive integer}`

Always blocked: `/api/identity/bootstrap` (do bootstrap through a dedicated local/CLI process in a future change); all catalog, import, EPG, playback and provider-management APIs. `GET /api/health` remains public.

## Deploy (safe while toggle is off)

```bash
cd ~/nsatellite
git pull --ff-only origin feat/web-source-imports
php -l deploy/o2switch/public_html/index.php
bash deploy/o2switch/install-public.sh
php bin/console cache:clear --env=prod
curl -i https://nsatellite.bragaatemorrer.fr/api/health
curl -i -X POST https://nsatellite.bragaatemorrer.fr/api/identity/login -H 'Content-Type: application/json' --data '{}'
curl -i https://nsatellite.bragaatemorrer.fr/api/catalog
```

Expect HTTP 200 on health, HTTP 404 on disabled login, and HTTP 403 on catalog. Ensure the root staging page is still displayed. Do not send passwords or admin tokens in terminal commands, logs or chat.

## Before enabling

Run comprehensive functional tests against a disposable MariaDB database; verify permission isolation, session expiry/revocation, login throttling, HTTP headers, request sizing and device association. Review and enforce HTTPS, trusted proxies/origin/cors rules and session/token storage. Do not enable during this preparatory deployment.
