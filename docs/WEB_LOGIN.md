# Nels TV web login — staged rollout

The staging landing page is replaced by a static, self-contained login screen in `deploy/o2switch/public_html/index.html`. It posts credentials to the same-origin `/api/identity/login` endpoint and keeps the session bearer token in JS memory only; the token is not persisted in browser storage. Reloading the page requires signing in again. Logout calls `/api/identity/logout`; the devices list comes from `GET /api/devices`.

**The API stays disabled by default.** Until `NELSTV_ENABLE_IDENTITY_API=1` is configured and verified by the operator, login returns 404 and the page displays a clear service-disabled message. Do not expose catalog, import, playback or bootstrap routes.

Install:
```bash
cd ~/nsatellite
git pull --ff-only origin feat/web-source-imports
bash deploy/o2switch/install-public.sh
php bin/console cache:clear --env=prod
```
Then open https://nsatellite.bragaatemorrer.fr/ and force reload.

Before enabling login, verify HTTPS, Symfony production mode, session revocation and throttling, secure rate-limiter directory permissions, MariaDB login with a real initial account, HTTP response security headers, login page behavior, and correct Apache allowlist. Use protected operational rollback and do not paste real credentials to chat or embed them in commands. The exact auth activation switch can be set by the operator in the private server environment only after all these checks.

Outstanding: formal full-flow live HTTP integration tests and browser CSP/header tuning, per-device lifecycle UI, secure owner-source grant interface, password reset/recovery, dedicated streaming service.
