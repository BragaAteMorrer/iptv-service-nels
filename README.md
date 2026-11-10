# IPTV Service Nels

Personal IPTV catalog and decoder with interchangeable historical TV interface themes (CanalSatellite, TPS, NOS). Use streams and provider access only when authorized.

## Import sources

| Source | Status |
| --- | --- |
| M3U/M3U8 | Streaming EXTINF parser added; upload/URL API pending |
| Xtream-compatible API | Initial client added; catalog persistence pending |
| MAG/MAC portals | Design documented; provider-specific authorized connector pending |

This branch is an **import foundation**, not a runnable receiver or complete Symfony backend.

**Stack direction:** PHP/Symfony, PostgreSQL, background workers, React TV UI, HLS playback and FFmpeg fallback.

See [source design](docs/SOURCES.md). Install PHP dependencies with `composer install`.

Never commit playlist URLs, login credentials, MAC subscriber identities, or provider tokens. Protect external requests against SSRF and honor distribution rights.

## Browser interface and sessions (o2switch)

The PC browser entry point is `/home.html`; `/tv.html` is the live TV
decoder. Both use the same backend as the catalog, series and devices pages.

Browser sessions use a 7-day Secure/HttpOnly, host-only cookie in production;
a logout revokes the corresponding hashed database session. Bearer tokens are
reserved for explicit API/device clients, not persisted in browser storage.

See [Browser session and security](docs/BROWSER_SESSION.md) and the
[opt-in o2switch release procedure](docs/O2SWITCH_VIEWER_RELEASE.md).
Merging `main` does not publish new pages: the server installation step is
separate, and playback availability depends on source authorization and
provider compatibility.

## Mon espace : gestion des accès IPTV par compte

La page `/account.html` permet à chaque utilisateur connecté d'ajouter
ses propres accès M3U (HTTPS) ou Xtream, de voir le statut des sources
autorisées, de modifier ses identifiants et de demander une synchronisation
asynchrone. Un compte ne peut pas modifier les sources d'un autre, ni les
sources simplement partagées avec lui. Aucun jeton administrateur global
n'est demandé sur les pages publiques.

**Migration impérative** : appliquer
`migrations/mariadb/003_source_owners.sql` sur MariaDB après avoir sauvegardé
la base, **avant** de déployer les nouvelles API. Les secrets fournisseurs
sont chiffrés à l'aide de `IPTV_SECRET_KEY`, et les domaines restent soumis
à `IPTV_TRUSTED_HOSTS`. L'import asynchrone exige un worker opérationnel.
Voir [le guide complet](docs/ACCOUNT_DASHBOARD.md).

La gestion globale des comptes et des sources partagées reste une tâche
d'administration serveur distincte, non exposée à tous les abonnés.
