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
