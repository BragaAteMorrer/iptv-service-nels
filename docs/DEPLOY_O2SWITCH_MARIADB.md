# Nels TV on o2switch Cloud — MariaDB and public_html

Deployment paths:
- Application: `/home/kapi9449/nsatellite`
- Web document root: `/home/kapi9449/nsatellite/public_html`
- Live host: `https://nsatellite.bragaatemorrer.fr`

The `public_html` directory is **required** by this deployment. Do not move the Symfony backend, `vendor/`, `.env`, source code or SQL migrations into that directory. The separate entrypoint in `deploy/o2switch/public_html/index.php` loads the project from its parent directory.

## Prepare public_html after dependencies are installed

From the application root:

```bash
cd ~/nsatellite
bash deploy/o2switch/install-public.sh
```

This preserves `public_html/.well-known` and copies static files from the project's `public/`. It does not delete existing files in the web directory. It is **not** a deployment approval: sensitive endpoints must be hardened first.

## Database: MariaDB is not yet implemented

The current codebase uses PostgreSQL DDL and SQL: `BIGSERIAL`, `TIMESTAMPTZ`, partial indexes, `ON CONFLICT`, `RETURNING`, and PostgreSQL interval expressions. Merely setting `DATABASE_URL=mysql://...` will not work. Do **not** run `migrations/001_initial.sql` through `007_vod_series.sql` on MariaDB and do not point the live site at the incompatible schema.

Migration work required before a MariaDB production release:
1. Translate all seven migrations to MariaDB-compatible schema, indexes, constraints, collations, and foreign keys; use bounded indexed VARCHAR IDs, including composite keys.
2. Replace PostgreSQL-specific INSERT / UPSERT / RETURNING, interval and text-search queries in PHP commands/controllers with MariaDB equivalents, or portable DBAL APIs.
3. Define a MariaDB DSN in private environment configuration; validate `pdo_mysql` and the **server** MariaDB version.
4. Run schema creation on an empty staging database, verify imports, preferences, playback metadata, account/device flows, and rollbacks.
5. Apply authentication rate limits, tenant/source access control and avoid publishing experimental HLS/MP4 proxy endpoints to a shared hosting account.

Before connecting, check environment:

```bash
php -m | grep -Ei 'pdo_mysql|mysqli'
php ~/bin/composer.phar check-platform-reqs
```

Keep credentials in server-local config, not Git or support chat. Keep `APP_ENV=prod`, `APP_DEBUG=0` in production.
