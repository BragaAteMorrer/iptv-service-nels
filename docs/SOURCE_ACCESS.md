# Source access control (staging only)

Source visibility is **deny-by-default** for personal library and indexed series endpoints. These endpoints require a valid account/device bearer token AND a `user_source_access` row. The same denial applies to mutation routes for favourites, channel numbers and viewing progress.

## Migrations

- MariaDB 11.4: `migrations/mariadb/002_source_access.sql` after `001_schema.sql`
- PostgreSQL Docker: `migrations/008_source_access.sql` after 007.

On the o2switch staging database ONLY:

```bash
cd ~/nsatellite
mariadb -u kapi9449_bragaatemorrer -p kapi9449_nelstv < migrations/mariadb/002_source_access.sql
php bin/console iptv:db:check
```

Expected count: **14 tables**. Never rerun the entire original schema on a nonempty database merely to add this table.

## Authorizing a source

A source must already exist; do not invent source IDs. Check the correct IDs privately with the SQL client:

```sql
SELECT id,email FROM app_users;
SELECT id,name,kind FROM sources;
```

Then explicitly grant a selected source to a selected account:

```sql
INSERT INTO user_source_access (user_id,source_id) VALUES (USER_ID,SOURCE_ID);
```

To revoke:

```sql
DELETE FROM user_source_access WHERE user_id=USER_ID AND source_id=SOURCE_ID;
```

Do not copy the placeholder identifiers literally. No existing source is shared automatically.

## Open security work — publication blocked

The legacy admin catalog and generic catalog discover/groups/summary, EPG and VOD/streaming controllers must also enforce authorization before any public exposure. The current o2switch `public_html` gate allows **only** `GET /api/health`, so this commit **does not** enable additional public routes. Review bootstrap, login/device authorization, access to metadata and side-channel leaks with integration tests and use an authorized source owner workflow before exposing client APIs.
