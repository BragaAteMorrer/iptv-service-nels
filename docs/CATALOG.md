# Catalog explorer

Open http://127.0.0.1:8080/catalog.html after setup/migration and import.

- GET /api/catalog/summary: live/movie/episode counts
- GET /api/catalog/groups?kind=live: most populated groups (top 500)
- GET /api/catalog/discover?kind=live&q=RTP&group=Portugal&page=1&limit=48: paged catalog search
- Header X-Admin-Token required. No playlist credential or stream URL is returned.
- Search and count are database-backed. A later iteration should introduce trigram indexes, keyset pagination and asynchronously materialized aggregate counts for catalog sizes above half a million items.
- Classification remains heuristic: provider group names and file extensions can be misleading, and Xtream series entries represent show-level records (not individual episodes).

Use only provider content to which you have legitimate access. Do not put access credentials into the repository.
