# Local setup (developer MVP)

1. Copy `.env.example` to `.env` and set APP_SECRET, IPTV_ADMIN_TOKEN (24+ random characters), and IPTV_SECRET_KEY (base64 encoded 32 random bytes).
2. Run `docker compose up --build -d`.
3. Initialize database: `docker compose exec -T database psql -U iptv -d iptv < migrations/001_initial.sql`.
4. Open http://127.0.0.1:8080/admin.html and enter the admin token.
5. Register a **M3U** source in the interface (the URL is metadata for now).
6. To import a *local* M3U file, put it in the container via a temporary bind mount or `docker cp playlist.m3u $(docker compose ps -q api):/tmp/playlist.m3u` and run `docker compose exec api php bin/console iptv:import:m3u SOURCE_ID /tmp/playlist.m3u`.
7. Query `GET /api/catalog?kind=live&page=1` with header `X-Admin-Token`.

Current limitations: source URL fetching is intentionally not enabled; Xtream remote synchronization and MAG portal handshake are not yet wired. Admin is a static HTML page; do not expose dev server publicly. No video playback is implemented. Playlist classification is heuristic; improve with group names and episode parsing.
