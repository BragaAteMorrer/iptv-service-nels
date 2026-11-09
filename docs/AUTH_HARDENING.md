# Authentication hardening — first stage

This change only hardens identity routes in PHP. **The o2switch public_html deployment continues to block all API routes except GET /api/health**. Do not enable login or pairing endpoints on the public Internet yet.

## Implemented

- `RequestRateLimiter` uses atomic `flock()` writes in `var/rate-limits/`, outside the web root, file mode 0600 and private directory 0700.
- Remote address comes from the server peer, not untrusted X-Forwarded-For.
- Fail closed if the state directory/file cannot be accessed.
- First account bootstrap: 5 attempts per peer IP / 24 hours.
- Login: 20 attempts per IP / 15 min and 5 attempts per account+IP / 15 min, including incorrect credentials. No email in file names.
- Device pairing start: 10 requests / IP / 10 min.
- Pair approval: 10 requests / account+IP / 15 min.
- Pair polling: 60 requests / IP / 1 min.
- Rejections return HTTP 429 and `Retry-After`.

## Validation

Run from `~/nsatellite`, NOT from `public_html`:

```bash
git pull --ff-only origin feat/web-source-imports
php -l src/Security/RequestRateLimiter.php
php -l src/Controller/IdentityController.php
php bin/console cache:clear --env=prod
php bin/console lint:container
curl -i https://nsatellite.bragaatemorrer.fr/api/health
curl -i https://nsatellite.bragaatemorrer.fr/api/identity/login
```

The last route must remain blocked externally. Never print `.env.local` or real IPTV provider URLs.

## Before activating authentication publicly

- Run actual functional tests with a disposable account and database, including response throttling after repeated invalid requests.
- Use a shared/distributed limiter (database or Redis) for multi-node environments and design retention/cleanup for local limiter files.
- Apply CSRF / origin policies appropriate to browser clients, secure transport, and per-user source ownership.
- Review bootstrap workflow, device pairing and bearer token lifecycle for token/session leakage and replay.
- Maintain a separate release gate for live/video relay: it is not suitable for open shared-hosting deployment.
