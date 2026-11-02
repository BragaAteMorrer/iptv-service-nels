# First Nels TV account (SSH only)

The initial account is created with `iptv:user:create-initial` directly on the hosting server; **the HTTP bootstrap route remains blocked**. Password is entered twice through hidden interactive prompts, never in shell history or command arguments. Password hashes use PHP `password_hash`.

## Preflight

```bash
cd ~/nsatellite
git pull --ff-only origin feat/web-source-imports
php -l src/Command/CreateInitialUserCommand.php
php bin/console cache:clear --env=prod
php bin/console list iptv
php bin/console iptv:db:check
```

Creation is intentionally a **separate conscious step**. Confirm you're using the intended database before running:

```bash
php bin/console iptv:user:create-initial your-email@example.com
```

Do not paste a real password, app secret, admin token, or database URL into chat. If the account already exists, the command will refuse to create another. Creation is guarded by a database advisory lock to avoid simultaneous initial-account CLI requests. No sources are shared by default; source grants require a separate explicit operation. The web identity API is still disabled unless explicitly enabled at the deployment gateway after additional review.
