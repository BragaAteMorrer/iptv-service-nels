# Sources and import contracts

Supported source types are **M3U/M3U8**, **Xtream-compatible player APIs**, and **authorized MAG/Stalker portals**.

## M3U / M3U8
- Import an uploaded file or URL from the backend.
- Stream and parse EXTINF rather than loading large playlists into memory.
- Preserve tvg-id, tvg-logo, group-title and original order.
- A standalone .m3u8 can be a *single HLS stream*, not a channel catalog: detect this separately.
- Classify live vs VOD heuristically and allow manual correction.

## Xtream
- Inputs: server HTTPS URL, username, password.
- Use provider-authorized player_api.php actions for live, VOD, series and EPG.
- Keep authentication fields encrypted at rest, redact in logs; never return raw connection details to an untrusted frontend.
- Validate server URLs before making requests: reject loopback, link-local, private networks and dangerous redirects; revalidate DNS/IP at connection time. Allow explicitly configured local providers only through a separate trusted-admin policy.
- Import asynchronously with pagination/batching where supported.

## MAG / MAC portals
- Inputs: portal HTTPS URL, an account-authorized MAC address, and provider-supplied access details when required.
- Protocol variants differ substantially; implement a per-provider connector after confirming its legitimate handshake and catalog format.
- Do not spoof third-party devices, evade subscriber restrictions, or guess tokens/credentials.
- No universal MAG connector is claimed implemented in this initial foundation.

## Shared catalog
Normalize imported items as: source_id, external_id, kind (live/movie/episode),
name, category, logo, epg_id, stream_reference, metadata, last_seen_at.
Store credentials outside these records. Build a mapping layer for user-defined channel numbers,
favorites, and historical skins (CanalSatellite, TPS, NOS). Support incremental
reimport and mark missing items inactive without destroying user preferences.

## Next implementation steps
1. Symfony API + PostgreSQL migrations, secret vault and source CRUD.
2. URL validation + async job pipeline and M3U upload/URL ingestion.
3. Xtream adapters and normalized live/VOD/series persistence.
4. Provider-specific authorized MAG connector and test fixtures.
5. EPG/XMLTV ingestion, playback adapter, and remote-controlled themed UI.
