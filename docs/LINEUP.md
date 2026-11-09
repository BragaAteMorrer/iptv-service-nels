# Channel lineup

Apply `migrations/002_channel_preferences.sql` after the initial schema.

Open `http://127.0.0.1:8080/lineup.html`. Authenticate with the same admin token as the source page.

Endpoints:
- GET /api/lineup?page=1&limit=50&favorites=1&include_hidden=0
- PATCH /api/lineup/{id}/preferences with any subset of `channel_number` (integer 1–99999 or null), `favorite` (boolean), `hidden` (boolean).

Preferences are separate from provider M3U/Xtream entries, so refreshing sources does not reset them. This is a single-owner catalog MVP; per-profile favorites, auto-numbering, hidden group rules, EPG integration, playable stream tickets and remote control navigation are later steps.

Requires authenticated management. Do not publish dev services to the Internet.
