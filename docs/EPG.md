# XMLTV programme guide and decoder prototype

Run migration 003_epg.sql after 001 and 002.

Import a **trusted local** XMLTV file (XML, not gzipped):
`docker compose exec api php bin/console iptv:import:xmltv /tmp/guide.xml`

Importer streams through XMLReader, with entity/network access disabled by LIBXML_NONET. Programme rows are upserted in 1000-row transaction batches. Match XMLTV `programme channel="..."` to M3U `tvg-id`. Where IDs differ, use authenticated `PUT /api/epg/channel/{id}/mapping` with JSON body `{"xmltv_channel":"provider.channel"}`.

`GET /api/epg/channel/{id}` returns the next 48h (plus recent programme history). Open `/tv.html` for the first remote-like UI, browse CH+/CH− or arrow keys. The decoder screen **does not play IPTV yet**: this step intentionally focuses on an EPG-driven channel banner and accessible zapping UI without exposing provider credentials.

Still required: authorization profiles, secure playback URL delivery, browser-codec compatibility, EPG network sync, pagination beyond 1000 channels, XMLTV stale programme cleanup, import idempotency tests, and theme variants.
